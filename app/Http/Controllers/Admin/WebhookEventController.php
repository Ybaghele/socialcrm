<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaWebhook;
use App\Models\WebhookEvent;
use App\Services\Admin\WorkspaceOverview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET  /admin/webhooks                     the raw inbound event log
 * POST /admin/webhooks/{event}/retry       re-queue a Meta event that failed
 *
 * This is the first place to look when a tenant says "my customer messaged and
 * nothing happened": the payload was stored before processing, so whatever broke
 * is still on disk. webhook_events is platform data (no workspace_id), so no
 * tenant context is involved.
 */
class WebhookEventController extends Controller
{
    private const STATUSES = ['pending', 'processed', 'failed', 'ignored'];

    public function index(Request $request): Response
    {
        $filters = [
            'status' => in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : 'all',
            'provider' => in_array($request->query('provider'), ['meta', 'razorpay', 'shopify'], true) ? (string) $request->query('provider') : 'all',
            'q' => trim((string) $request->query('q', '')),
        ];

        $events = WebhookEvent::query()
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['provider'] !== 'all', fn ($query) => $query->where('provider', $filters['provider']))
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';

                $query->where(function ($query) use ($term) {
                    $query->where('external_id', 'like', $term)
                        ->orWhere('event_type', 'like', $term);
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $channelNames = WorkspaceOverview::channelNames($events->pluck('channel_account_id')->all());

        $events->setCollection(collect($events->items())->map(fn (WebhookEvent $event): array => [
            'id' => $event->id,
            'provider' => $event->provider,
            'eventType' => $event->event_type,
            'externalId' => $event->external_id,
            'status' => $event->status,
            'error' => $event->error,
            'channelAccount' => $event->channel_account_id ? ($channelNames[$event->channel_account_id] ?? null) : null,
            'payload' => $event->payload,
            'processedAt' => $event->processed_at?->toIso8601String(),
            'createdAt' => $event->created_at?->toIso8601String(),
            // Only Meta events have a re-queue path today.
            'retryable' => $event->provider === 'meta' && $event->status !== 'processed',
        ]));

        return Inertia::render('Admin/Webhooks', [
            'filters' => $filters,
            'events' => $events,
            'counts' => [
                'pending' => WebhookEvent::query()->where('status', 'pending')->count(),
                'processed' => WebhookEvent::query()->where('status', 'processed')->count(),
                'failed' => WebhookEvent::query()->where('status', 'failed')->count(),
                'ignored' => WebhookEvent::query()->where('status', 'ignored')->count(),
            ],
        ]);
    }

    public function retry(WebhookEvent $webhookEvent): RedirectResponse
    {
        abort_unless($webhookEvent->provider === 'meta', 422, 'Only Meta events can be retried here.');
        abort_if($webhookEvent->status === 'processed', 409, 'This event has already been processed.');

        // ProcessMetaWebhook bails out early only for "processed" rows, so flipping
        // the status back to pending is enough to make the retry take effect.
        $webhookEvent->forceFill(['status' => 'pending', 'error' => null])->save();

        ProcessMetaWebhook::dispatch($webhookEvent->id);

        return back()->with('success', "Webhook event #{$webhookEvent->id} queued for another attempt.");
    }
}
