<?php

namespace App\Services\Automations;

use App\Actions\SendOutboundMessage;
use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbound\RoundRobinAssigner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the "on a new inbound message" half of the automation module: routing and
 * auto-replies. Follow-up sequences are time-driven, so they live in
 * ProcessAutomationSequences instead.
 *
 * Rules are evaluated in priority order and the FIRST match wins, for both types.
 * That is what lets an operator stack "refund" → "pricing" → "anything else" and
 * get exactly one reply.
 *
 * Everything here is called from inside the inbound transaction, so it never
 * throws: a broken rule must not be able to lose a customer's message.
 */
final class AutomationEngine
{
    public function __construct(
        private SendOutboundMessage $send,
        private RoundRobinAssigner $router,
        private BusinessHours $hours,
    ) {}

    public function handleInbound(Conversation $conversation, Message $message, bool $firstInThread): void
    {
        $workspace = $conversation->workspace;

        if (! $workspace) {
            return;
        }

        $this->route($workspace, $conversation, $firstInThread);
        $this->reply($workspace, $conversation, $message, $firstInThread);
    }

    // ---- Routing -----------------------------------------------------------

    /** Claim a brand-new thread for an agent. Only the first message routes. */
    private function route(Workspace $workspace, Conversation $conversation, bool $firstInThread): void
    {
        if (! $firstInThread) {
            return;
        }

        foreach ($this->rules($workspace, Automation::TYPE_ROUTING) as $rule) {
            if (! $this->matches($rule, $conversation, null, $firstInThread)) {
                continue;
            }

            $userId = $this->pickAssignee($workspace, $rule, $conversation);

            if (! $userId) {
                $this->mark($rule, $conversation, 'cancelled');

                continue;
            }

            $conversation->forceFill(['assigned_user_id' => $userId])->save();
            $conversation->contact?->forceFill(['assigned_user_id' => $userId])->save();

            ActivityLog::create([
                'contact_id' => $conversation->contact_id,
                'event' => 'assigned',
                'description' => "Assigned by the “{$rule->name}” automation",
                'properties' => ['assigned_user_id' => $userId, 'automation_id' => $rule->id],
            ]);

            $this->mark($rule, $conversation, 'completed');
            $this->touch($rule);

            return; // first matching rule wins
        }
    }

    /**
     * The agent a routing rule hands the conversation to.
     *
     * The two pool strategies both rotate by workload rather than by the pivot's
     * last_assigned_at alone, because the default round-robin router also stamps
     * that column for every new contact — counting conversations keeps the choice
     * honest about who has actually been given work.
     */
    private function pickAssignee(Workspace $workspace, Automation $rule, Conversation $conversation): ?int
    {
        $actions = $rule->actions ?? [];
        $strategy = (string) ($actions['strategy'] ?? 'round_robin');
        $pool = array_values(array_filter(array_map('intval', (array) ($actions['user_ids'] ?? []))));

        if ($strategy === 'specific_user') {
            $id = (int) ($actions['user_id'] ?? 0);

            return $id > 0 && $workspace->users()->whereKey($id)->exists() ? $id : null;
        }

        $candidates = $workspace->users()
            ->wherePivot('is_available', true)
            ->when($pool !== [], fn ($query) => $query->whereIn('users.id', $pool))
            ->get();

        // An empty pool means "anyone available" — fall back to the shared router,
        // which also stamps last_assigned_at for us.
        if ($candidates->isEmpty()) {
            return $pool === [] ? $this->router->next($workspace)?->id : null;
        }

        $ids = $candidates->pluck('id');

        $counts = Conversation::query()
            ->whereKeyNot($conversation->getKey()) // this thread already carries a provisional assignee
            ->whereIn('assigned_user_id', $ids)
            ->when($strategy === 'least_busy', fn ($query) => $query->where('status', 'open'))
            ->selectRaw('assigned_user_id, count(*) as total')
            ->groupBy('assigned_user_id')
            ->pluck('total', 'assigned_user_id');

        $chosen = $candidates
            ->sortBy(fn (User $user) => [
                (int) ($counts[$user->id] ?? 0),
                (string) ($user->pivot->last_assigned_at ?? ''),
            ])
            ->first();

        if (! $chosen) {
            return null;
        }

        DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $chosen->id)
            ->update(['last_assigned_at' => now()]);

        return $chosen->id;
    }

    // ---- Auto-replies ------------------------------------------------------

    private function reply(Workspace $workspace, Conversation $conversation, Message $message, bool $firstInThread): void
    {
        // Never answer a "stop" with marketing copy — opt-out wins.
        if ($conversation->contact && ! $conversation->contact->is_opted_in) {
            return;
        }

        $cooldown = (int) config('omniflow.automations.reply_cooldown_minutes', 60);

        foreach ($this->rules($workspace, Automation::TYPE_AUTO_REPLY) as $rule) {
            if (! $this->matches($rule, $conversation, $message, $firstInThread)) {
                continue;
            }

            $body = trim((string) ($rule->actions['reply'] ?? ''));

            if ($body === '') {
                return;
            }

            $run = AutomationRun::where('automation_id', $rule->id)
                ->where('contact_id', $conversation->contact_id)
                ->first();

            if ($run?->last_sent_at && $run->last_sent_at->gt(now()->subMinutes($cooldown))) {
                return; // already answered this contact moments ago
            }

            try {
                $this->send->handle($conversation, null, $body);
            } catch (Throwable $e) {
                Log::warning('Automation auto-reply skipped', [
                    'automation_id' => $rule->id,
                    'conversation_id' => $conversation->id,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }

            $assign = (int) ($rule->actions['assign_user_id'] ?? 0);

            if ($assign > 0 && $workspace->users()->whereKey($assign)->exists()) {
                $conversation->forceFill(['assigned_user_id' => $assign])->save();
            }

            $this->mark($rule, $conversation, 'completed', now());
            $this->touch($rule);

            return; // first matching rule wins
        }
    }

    // ---- Shared ------------------------------------------------------------

    /** @return Collection<int, Automation> */
    private function rules(Workspace $workspace, string $type): Collection
    {
        return Automation::query()
            ->ofType($type)
            ->active()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /** Does this rule's trigger describe this message? */
    private function matches(Automation $rule, Conversation $conversation, ?Message $message, bool $firstInThread): bool
    {
        $conditions = $rule->conditions ?? [];

        $channels = array_values(array_filter((array) ($conditions['channels'] ?? [])));

        if ($channels !== [] && ! in_array($conversation->channel, $channels, true)) {
            return false;
        }

        if (! empty($conditions['outside_business_hours']) && $this->hours->isOpen($conversation->workspace)) {
            return false;
        }

        if (! empty($conditions['only_first_message']) && ! $firstInThread) {
            return false;
        }

        $keywords = array_values(array_filter(array_map(
            'trim',
            (array) ($conditions['keywords'] ?? []),
        )));

        if ($keywords === []) {
            return true; // no keyword list means "any message"
        }

        $body = mb_strtolower((string) ($message->body ?? ''));

        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($body, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    /** Upsert the (automation, contact) ledger row that dedupes this rule. */
    private function mark(Automation $rule, Conversation $conversation, string $status, ?object $sentAt = null): AutomationRun
    {
        $attributes = [
            'workspace_id' => $rule->workspace_id,
            'conversation_id' => $conversation->id,
            'status' => $status,
        ];

        if ($sentAt) {
            $attributes['last_sent_at'] = $sentAt;
        }

        return AutomationRun::updateOrCreate(
            ['automation_id' => $rule->id, 'contact_id' => $conversation->contact_id],
            $attributes,
        );
    }

    private function touch(Automation $rule): void
    {
        $rule->forceFill(['last_run_at' => now()])->save();
        $rule->increment('run_count');
    }
}
