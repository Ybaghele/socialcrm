<?php

namespace App\Jobs\Automations;

use App\Actions\SendOutboundMessage;
use App\Exceptions\InsufficientBalance;
use App\Exceptions\OutboundNotAllowed;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Workspace;
use App\Support\MergeTags;
use App\Support\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as QueueableTrait;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The time-driven half of the automation module: follow-up sequences.
 *
 * Each tick, for every active sequence automation, it
 *
 *   1. enrols quiet threads — conversations whose last message was ours and where
 *      the customer has been silent for the configured number of hours, and
 *   2. sends the next due step for anyone already enrolled.
 *
 * A reply from the customer cancels the run, and enrolment always requires an
 * opted-in contact, so a sequence can never message someone who has opted out.
 *
 * Steps are approved templates sent through {@see SendOutboundMessage}, so wallet
 * debits, the inbox thread and Meta delivery behave exactly like a manual send.
 */
class ProcessAutomationSequences implements ShouldQueue
{
    use InteractsWithQueue, Queueable, QueueableTrait, SerializesModels;

    public int $tries = 3;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(SendOutboundMessage $send): void
    {
        $automations = Automation::withoutGlobalScopes()
            ->where('type', Automation::TYPE_SEQUENCE)
            ->where('status', 'active')
            ->get();

        foreach ($automations as $automation) {
            $workspace = Workspace::find($automation->workspace_id);

            if (! $workspace) {
                continue;
            }

            Tenancy::run($workspace, function () use ($automation, $workspace, $send) {
                $steps = $automation->steps()->get()->values();

                if ($steps->isEmpty()) {
                    return;
                }

                $this->enrol($automation, $workspace, $steps->first());
                $this->advance($automation, $workspace, $send, $steps);
            });
        }
    }

    /**
     * Pull in conversations where the customer has gone quiet.
     *
     * @param  Collection<int, AutomationStep>  $steps
     */
    private function enrol(Automation $automation, Workspace $workspace, $firstStep): void
    {
        $conditions = $automation->conditions ?? [];
        $hours = (int) ($conditions['no_reply_after_hours'] ?? 0);

        if ($hours <= 0) {
            return;
        }

        $channels = array_values(array_filter((array) ($conditions['channels'] ?? [])));
        $limit = (int) config('omniflow.automations.enrol_batch', 200);

        $conversations = Conversation::query()
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', now()->subHours($hours))
            // The last word was ours: either they never wrote, or their message
            // predates our most recent one.
            ->where(fn ($query) => $query
                ->whereNull('last_inbound_at')
                ->orWhereColumn('last_inbound_at', '<', 'last_message_at'))
            ->when($channels !== [], fn ($query) => $query->whereIn('channel', $channels))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($conversations as $conversation) {
            $contact = Contact::find($conversation->contact_id);

            if (! $contact || ! $contact->is_opted_in) {
                continue;
            }

            $already = AutomationRun::where('automation_id', $automation->id)
                ->where('contact_id', $contact->id)
                ->exists();

            if ($already) {
                continue;
            }

            // Only chase threads we have actually messaged.
            $weWrote = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'outbound')
                ->exists();

            if (! $weWrote) {
                continue;
            }

            AutomationRun::create([
                'automation_id' => $automation->id,
                'workspace_id' => $workspace->id,
                'contact_id' => $contact->id,
                'conversation_id' => $conversation->id,
                'status' => 'active',
                'current_step' => 0,
                'next_run_at' => now()->addMinutes((int) $firstStep->delay_minutes),
            ]);
        }
    }

    /**
     * Send the next due step for every enrolled contact.
     *
     * @param  Collection<int, AutomationStep>  $steps
     */
    private function advance(Automation $automation, Workspace $workspace, SendOutboundMessage $send, $steps): void
    {
        $limit = (int) config('omniflow.automations.send_batch', 50);

        $runs = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('next_run_at')
                ->orWhere('next_run_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($runs as $run) {
            $conversation = Conversation::find($run->conversation_id);

            if (! $conversation) {
                $run->update(['status' => 'cancelled', 'next_run_at' => null]);

                continue;
            }

            // They wrote back after our last step — the chase is over.
            $since = $run->last_sent_at ?? $run->created_at;

            $replied = Message::where('conversation_id', $conversation->id)
                ->where('direction', 'inbound')
                ->where('created_at', '>', $since)
                ->exists();

            if ($replied) {
                $run->update(['status' => 'cancelled', 'next_run_at' => null]);

                continue;
            }

            $step = $steps[$run->current_step] ?? null;
            $contact = $conversation->contact;

            if (! $step || ! $contact) {
                $run->update(['status' => 'cancelled', 'next_run_at' => null]);

                continue;
            }

            $template = $step->template;

            if (! $template || ! $template->isApproved()) {
                $this->cancel($run, $automation, 'the template is not approved');

                continue;
            }

            try {
                $send->handle($conversation, null, null, $template, $this->variables($step, $template, $contact));
            } catch (OutboundNotAllowed|InsufficientBalance $e) {
                // Opted out, disconnected number or an empty wallet: stop chasing
                // rather than retrying every minute forever.
                $this->cancel($run, $automation, $e->getMessage());

                continue;
            }

            $next = $run->current_step + 1;

            if ($next >= $steps->count()) {
                $run->update([
                    'status' => 'completed',
                    'current_step' => $next,
                    'last_sent_at' => now(),
                    'next_run_at' => null,
                ]);
            } else {
                $run->update([
                    'current_step' => $next,
                    'last_sent_at' => now(),
                    'next_run_at' => now()->addMinutes((int) $steps[$next]->delay_minutes),
                ]);
            }

            $automation->forceFill(['last_run_at' => now()])->save();
            $automation->increment('run_count');
        }
    }

    /**
     * Always build exactly as many variables as the template declares: a missing
     * slot falls back to the contact's first name so Meta never sees a blank.
     *
     * @return array<int, string>
     */
    private function variables(AutomationStep $step, MessageTemplate $template, Contact $contact): array
    {
        $stored = $step->template_variables ?? [];
        $count = $template->placeholderCount();

        $variables = [];

        for ($i = 0; $i < $count; $i++) {
            $value = trim((string) ($stored[$i] ?? ''));

            $variables[] = $value === ''
                ? MergeTags::firstName($contact)
                : MergeTags::expand($value, $contact);
        }

        return $variables;
    }

    private function cancel(AutomationRun $run, Automation $automation, string $reason): void
    {
        $run->update(['status' => 'cancelled', 'next_run_at' => null]);

        Log::warning('Automation sequence stopped', [
            'automation_id' => $automation->id,
            'run_id' => $run->id,
            'reason' => $reason,
        ]);
    }
}
