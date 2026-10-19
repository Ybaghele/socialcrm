<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\Admin\WorkspaceOverview;
use App\Services\Billing\PlanLimits;
use App\Services\Billing\WalletService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform management of tenants.
 *
 * {workspace} is the numeric id (implicit model binding), NOT the slug: these
 * routes never pass through ResolveWorkspace, so there is no tenant context and
 * every tenant-scoped read is wrapped in Tenancy::run() by hand. A super admin is
 * deliberately able to reach a suspended workspace.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['active', 'suspended'], true)
                ? (string) $request->query('status')
                : 'all',
        ];

        $workspaces = Workspace::query()
            ->with(['owner:id,name,email', 'subscription.plan'])
            ->when($filters['q'] !== '', function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';

                $query->where(function ($query) use ($term) {
                    $query->where('name', 'like', $term)
                        ->orWhere('slug', 'like', $term)
                        ->orWhereHas('owner', fn ($owner) => $owner
                            ->where('email', 'like', $term)
                            ->orWhere('name', 'like', $term));
                });
            })
            ->when($filters['status'] !== 'all', fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Swap in the summarised rows while keeping the paginator's links/meta.
        $workspaces->setCollection(collect(WorkspaceOverview::summarize(collect($workspaces->items()))));

        return Inertia::render('Admin/Workspaces', [
            'filters' => $filters,
            'workspaces' => $workspaces,
        ]);
    }

    public function show(Workspace $workspace): Response
    {
        $workspace->load(['owner:id,name,email', 'subscription.plan']);

        $limits = PlanLimits::for($workspace);
        $plan = $limits->plan();

        $wallet = Wallet::query()->where('workspace_id', $workspace->id)->first();

        $counts = Tenancy::run($workspace, fn (): array => [
            'contacts' => Contact::query()->count(),
            'optedIn' => Contact::query()->where('is_opted_in', true)->count(),
            'conversations' => Conversation::query()->count(),
            'openConversations' => Conversation::query()->where('status', 'open')->count(),
            'messages' => Message::query()->count(),
            'campaigns' => Campaign::query()->count(),
            'templates' => MessageTemplate::query()->count(),
            'automations' => Automation::query()->count(),
        ]);

        $channels = Tenancy::run($workspace, fn (): array => [
            'ids' => ChannelAccount::query()->pluck('id')->all(),
            'instagram' => ChannelAccount::query()->where('channel', 'instagram')->count(),
            'total' => ChannelAccount::query()->count(),
        ]);

        $events = $channels['ids'] === []
            ? collect()
            : WebhookEvent::query()
                ->whereIn('channel_account_id', $channels['ids'])
                ->latest('id')
                ->limit(10)
                ->get();

        $members = $workspace->users()
            ->orderBy('name')
            ->get()
            ->sortBy(fn ($user) => ['owner' => 0, 'admin' => 1, 'agent' => 2][$user->pivot->role] ?? 3)
            ->values();

        return Inertia::render('Admin/Workspace', [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'status' => $workspace->status?->value ?? 'active',
                'suspendedAt' => $workspace->suspended_at?->toIso8601String(),
                'suspensionReason' => $workspace->suspension_reason,
                'timezone' => $workspace->timezone,
                'currency' => $workspace->currency,
                'limitsOverride' => $workspace->limits_override ?? [],
                'onboardedAt' => $workspace->onboarded_at?->toIso8601String(),
                'createdAt' => $workspace->created_at?->toIso8601String(),
            ],
            'owner' => $workspace->owner ? [
                'name' => $workspace->owner->name,
                'email' => $workspace->owner->email,
            ] : null,
            'plan' => $plan ? [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'pricePaise' => (int) $plan->price_paise,
                'monthlyActiveContacts' => (int) $plan->monthly_active_contacts,
                'maxAgentSeats' => (int) $plan->max_agent_seats,
                'maxInstagramAccounts' => (int) $plan->max_instagram_accounts,
            ] : null,
            'subscription' => $workspace->subscription ? [
                'status' => $workspace->subscription->status,
                'trialEndsAt' => $workspace->subscription->trial_ends_at?->toIso8601String(),
                'currentPeriodEnd' => $workspace->subscription->current_period_end?->toIso8601String(),
                'hasAccess' => $workspace->hasAccess(),
            ] : null,
            'usage' => [
                'contactsUsed' => $limits->activeContactsUsed(),
                'contactsLimit' => $plan?->monthly_active_contacts,
                'contactsPercent' => $plan?->monthly_active_contacts
                    ? min(100, (int) round($limits->activeContactsUsed() / $plan->monthly_active_contacts * 100))
                    : 0,
                'overageBlocks' => $limits->overageBlocks(),
                'seatsUsed' => $limits->seatsUsed(),
                'seatLimit' => $limits->seatLimit(),
                'instagramUsed' => $channels['instagram'],
                'instagramLimit' => $plan?->max_instagram_accounts,
                'channels' => $channels['total'],
            ],
            'wallet' => [
                'balancePaise' => (int) ($wallet?->balance_paise ?? 0),
                'estimatedMessages' => (int) ($wallet?->estimated_messages ?? 0),
                'perMessagePaise' => (int) config('omniflow.pricing.whatsapp_template_paise.marketing', 88),
            ],
            'transactions' => WalletTransaction::query()
                ->where('workspace_id', $workspace->id)
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (WalletTransaction $tx): array => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'reason' => $tx->reason,
                    'amountPaise' => (int) $tx->amount_paise,
                    'balanceAfterPaise' => (int) $tx->balance_after_paise,
                    'description' => $tx->description,
                    'createdAt' => $tx->created_at?->toIso8601String(),
                ])
                ->all(),
            'payments' => Tenancy::run($workspace, fn () => Payment::query()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'invoiceNumber' => $payment->invoice_number,
                    'purpose' => $payment->purpose,
                    'amountPaise' => (int) $payment->amount_paise,
                    'status' => $payment->status,
                    'paidAt' => $payment->paid_at?->toIso8601String(),
                ])
                ->all()),
            'counts' => $counts,
            'members' => $members->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'jobTitle' => $user->pivot->job_title,
            ])->all(),
            'events' => $events->map(fn (WebhookEvent $event): array => [
                'id' => $event->id,
                'provider' => $event->provider,
                'eventType' => $event->event_type,
                'status' => $event->status,
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function suspend(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $workspace->forceFill([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => $data['reason'] ?? null,
        ])->save();

        return back()->with('success', "{$workspace->name} is now suspended.");
    }

    public function activate(Workspace $workspace): RedirectResponse
    {
        $workspace->forceFill([
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        return back()->with('success', "{$workspace->name} is active again.");
    }

    public function extend(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $subscription = $workspace->subscription;

        abort_unless($subscription instanceof Subscription, 404, 'This workspace has no subscription to extend.');

        // Extend whichever date is actually the tenant's clock: a trial while
        // they are trialing, otherwise the paid period. A lapsed trial is
        // extended from *now* so one click restores access.
        $column = $subscription->status === 'trialing' ? 'trial_ends_at' : 'current_period_end';
        $current = $subscription->{$column};
        $base = $current && $current->isFuture() ? $current->copy() : now();

        $subscription->forceFill([$column => $base->addDays((int) $data['days'])])->save();

        return back()->with(
            'success',
            sprintf('%s extended by %d day(s) — now until %s.', $workspace->name, (int) $data['days'], $base->addDays((int) $data['days'])->format('j M Y')),
        );
    }

    public function updateLimits(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'monthly_active_contacts' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'max_agent_seats' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'max_instagram_accounts' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        // Blank fields clear the override so the tenant falls back to their plan.
        $overrides = array_filter(
            $data,
            fn ($value) => $value !== null && $value !== '',
        );

        $workspace->forceFill(['limits_override' => $overrides === [] ? null : $overrides])->save();

        return back()->with('success', 'Plan limit overrides updated.');
    }

    public function credit(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'], // rupees
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        // Guarantee the wallet row exists before the service row-locks it.
        Wallet::firstOrCreate(['workspace_id' => $workspace->id], ['balance_paise' => 0]);

        app(WalletService::class)->credit(
            $workspace,
            (int) $data['amount'] * 100,
            'adjustment',
            null,
            $data['description'] ?: 'Goodwill credit by platform support',
        );

        return back()->with('success', '₹'.number_format((int) $data['amount']).' credited to '.$workspace->name.'.');
    }
}
