<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\Admin\WorkspaceOverview;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /admin — the platform overview.
 *
 * Everything tenant-owned (contacts, conversations, messages) has to be counted
 * inside Tenancy::runUnscoped(), because their global scope throws without a
 * tenant context. Platform tables (workspaces, subscriptions, wallets, users,
 * webhook_events) are not tenant-scoped and are queried directly.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        $since = now()->subDays(30);

        [$contacts, $conversations, $messages30d] = Tenancy::runUnscoped(fn (): array => [
            Contact::query()->count(),
            Conversation::query()->count(),
            Message::query()->where('created_at', '>=', $since)->count(),
        ]);

        $subscriptionStatuses = Subscription::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $mrrPaise = (int) Subscription::query()
            ->where('status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.price_paise');

        $lowThreshold = (int) config('omniflow.billing.low_balance_paise', 5000);

        $recentWorkspaces = Workspace::query()
            ->with(['owner:id,name,email', 'subscription.plan'])
            ->latest('id')
            ->limit(6)
            ->get();

        $recentEvents = WebhookEvent::query()->latest('id')->limit(8)->get();
        $channelNames = WorkspaceOverview::channelNames($recentEvents->pluck('channel_account_id')->all());

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'workspaces' => [
                    'total' => Workspace::query()->count(),
                    'active' => Workspace::query()->where('status', 'active')->count(),
                    'suspended' => Workspace::query()->where('status', 'suspended')->count(),
                ],
                'users' => [
                    'total' => User::query()->count(),
                    'staff' => User::query()->where('is_super_admin', true)->count(),
                ],
                'subscriptions' => [
                    'active' => (int) ($subscriptionStatuses['active'] ?? 0),
                    'trialing' => (int) ($subscriptionStatuses['trialing'] ?? 0),
                    'pastDue' => (int) ($subscriptionStatuses['past_due'] ?? 0),
                    'cancelled' => (int) ($subscriptionStatuses['cancelled'] ?? 0),
                ],
                'mrrPaise' => $mrrPaise,
                'contacts' => $contacts,
                'conversations' => $conversations,
                'messages30d' => $messages30d,
                'walletTotalPaise' => (int) Wallet::query()->sum('balance_paise'),
                'walletLowCount' => Wallet::query()->where('balance_paise', '<', $lowThreshold)->count(),
                'webhooks' => [
                    'pending' => WebhookEvent::query()->where('status', 'pending')->count(),
                    'failed' => WebhookEvent::query()->where('status', 'failed')->count(),
                    'processed24h' => WebhookEvent::query()
                        ->where('status', 'processed')
                        ->where('processed_at', '>=', now()->subDay())
                        ->count(),
                ],
            ],
            'recentWorkspaces' => WorkspaceOverview::summarize($recentWorkspaces),
            'recentEvents' => $recentEvents->map(fn (WebhookEvent $event): array => [
                'id' => $event->id,
                'provider' => $event->provider,
                'eventType' => $event->event_type,
                'status' => $event->status,
                'channelAccount' => $event->channel_account_id ? ($channelNames[$event->channel_account_id] ?? null) : null,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
