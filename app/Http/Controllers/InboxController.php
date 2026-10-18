<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\CannedResponse;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /w/{workspace}/inbox
 *
 * One screen, three columns: conversation list, active thread, lead dossier.
 * Everything is read from the tenant-scoped models (ResolveWorkspace set the
 * context), so another workspace's rows can never show up here.
 *
 * The selected thread is addressed with ?conversation={id} so the URL is
 * shareable and a browser refresh keeps the same thread open.
 */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        $filters = [
            'channel' => (string) $request->string('channel')->toString() ?: 'all',
            'status' => (string) $request->string('status')->toString() ?: 'all',
            'q' => trim((string) $request->string('q')->toString()),
        ];

        $conversations = Conversation::query()
            ->with([
                'contact:id,name,phone,avatar_url,city,state,pipeline_stage_id,last_interaction_at',
                'contact.stage:id,name,color',
                'contact.tags:id,name,color',
                'contact.identities:id,contact_id,channel,handle',
                'assignee:id,name',
            ])
            ->when($filters['channel'] !== 'all', fn ($q) => $q->where('channel', $filters['channel']))
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';

                $q->where(function ($q) use ($term) {
                    $q->whereHas('contact', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                        ->orWhereHas('contact.identities', fn ($i) => $i->where('handle', 'like', $term));
                });
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $requestedId = $request->integer('conversation');
        $selected = $conversations->firstWhere('id', $requestedId) ?? $conversations->first();

        // Opening a thread clears its unread badge (the badge is what the tab strip counts).
        if ($selected && $selected->unread_count > 0 && $requestedId === $selected->id) {
            $selected->forceFill(['unread_count' => 0])->save();
        }

        return Inertia::render('Inbox', [
            'filters' => $filters,
            'conversations' => $conversations->map(fn (Conversation $c) => $this->conversationPayload($c))->values(),
            'selectedId' => $selected?->id,
            'thread' => $selected ? $this->threadPayload($selected) : null,
            'messages' => $selected ? $this->messages($selected) : [],
            'pipelineStages' => PipelineStage::query()
                ->orderBy('position')
                ->get(['id', 'name', 'color', 'is_won'])
                ->map(fn (PipelineStage $stage) => [
                    'id' => $stage->id,
                    'name' => $stage->name,
                    'color' => $stage->color,
                    'isWon' => (bool) $stage->is_won,
                ]),
            'cannedResponses' => CannedResponse::query()
                ->orderBy('title')
                ->get(['id', 'title', 'body', 'shortcut'])
                ->map(fn (CannedResponse $r) => [
                    'id' => $r->id,
                    'title' => $r->title,
                    'body' => $r->body,
                    'shortcut' => $r->shortcut,
                ]),
        ]);
    }

    /**
     * POST /w/{workspace}/inbox/simulate
     *
     * Runs the real inbound pipeline (same command SETUP.md documents) so the UI
     * can be exercised without a Meta app. Always allowed in local; in production
     * it is limited to the workspace owner / super admin so customers can't inject
     * fake threads into their own inbox with no Meta app configured.
     */
    public function simulate(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);

        abort_unless($this->maySimulateInbound($request), 404);

        $data = $request->validate([
            'channel' => ['nullable', 'in:whatsapp,instagram,messenger'],
            'text' => ['nullable', 'string', 'max:1000'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        Artisan::call('omniflow:simulate-inbound', [
            'workspace' => $workspace->slug,
            '--channel' => $data['channel'] ?? 'whatsapp',
            '--text' => $data['text'] ?? 'Hi! Can you share pricing and availability?',
            '--name' => $data['name'] ?? 'Inbound Lead',
            '--sync' => true,
        ]);

        return back()->with('success', 'Simulated inbound message processed — check the top of the list.');
    }

    /**
     * The simulator exists so a workspace can be demoed before a Meta app is
     * wired up. Anyone may use it locally; in production only the owner (or a
     * super admin) may, since it writes real conversations into the tenant.
     */
    private function maySimulateInbound(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return $request->attributes->get('workspace_role') === WorkspaceRole::Owner->value
            || (bool) $request->user()?->is_super_admin;
    }

    private function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 404);

        return $workspace;
    }

    /** @return array<string, mixed> */
    private function conversationPayload(Conversation $conversation): array
    {
        $contact = $conversation->contact;

        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel,
            'status' => $conversation->status,
            'unread' => (int) $conversation->unread_count,
            'lastMessage' => $conversation->last_message_preview,
            'lastTime' => $conversation->last_message_at?->toIso8601String(),
            'assignedTo' => $conversation->assignee?->name,
            'contact' => [
                'id' => $contact?->id,
                'name' => $contact?->name ?? 'Unknown',
                'phone' => $contact?->phone,
                'avatarUrl' => $contact?->avatar_url,
                'location' => collect([$contact?->city, $contact?->state])->filter()->implode(', ') ?: null,
                'stage' => $contact?->stage ? [
                    'id' => $contact->stage->id,
                    'name' => $contact->stage->name,
                    'color' => $contact->stage->color,
                ] : null,
                'tags' => $contact?->tags->map(fn ($tag) => ['id' => $tag->id, 'name' => $tag->name, 'color' => $tag->color])->values() ?? [],
                'handle' => $contact?->identities->firstWhere('handle', '!=', null)?->handle,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function threadPayload(Conversation $conversation): array
    {
        $payload = $this->conversationPayload($conversation);
        $lastInbound = $conversation->last_inbound_at;

        return [
            ...$payload,
            'firstResponseAt' => $conversation->first_response_at?->toIso8601String(),
            'resolvedAt' => $conversation->resolved_at?->toIso8601String(),
            'withinWindow' => $conversation->isWithinReplyWindow(),
            'windowClosesAt' => $lastInbound?->copy()->addDay()->toIso8601String(),
            'lastInboundAt' => $lastInbound?->toIso8601String(),
            'totalMessages' => $conversation->messages()->count(),
            'activity' => $conversation->contact
                ? $conversation->contact->activityLogs()->limit(5)->get()->map(fn ($log) => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'description' => $log->description,
                    'properties' => $log->properties,
                    'author' => $log->user?->name,
                    'createdAt' => $log->created_at?->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function messages(Conversation $conversation): array
    {
        return $conversation->messages()
            ->with('author:id,name')
            ->limit(200)
            ->get()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'senderType' => $message->sender_type,
                'body' => $message->body,
                'type' => $message->type,
                'status' => $message->status,
                'author' => $message->author?->name,
                'createdAt' => $message->created_at?->toIso8601String(),
                'isInternal' => $message->direction === 'internal',
            ])
            ->all();
    }
}
