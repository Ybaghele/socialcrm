<?php

namespace App\Services\Admin;

use App\Models\ChannelAccount;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant "at a glance" rows for the platform (super-admin) console.
 *
 * Every number is read from the real platform tables — workspaces, subscriptions,
 * wallets, workspace_user — and the quota figures are borrowed from PlanLimits so
 * the back-office can never drift from what the tenant's own Billing screen shows.
 */
final class WorkspaceOverview
{
    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return array<int, array<string, mixed>>
     */
    public static function summarize(Collection $workspaces): array
    {
        if ($workspaces->isEmpty()) {
            return [];
        }

        $ids = $workspaces->pluck('id')->all();

        // Two batched lookups instead of one query per workspace.
        $balances = Wallet::query()
            ->whereIn('workspace_id', $ids)
            ->pluck('balance_paise', 'workspace_id');

        $memberCounts = DB::table('workspace_user')
            ->whereIn('workspace_id', $ids)
            ->selectRaw('workspace_id, count(*) as total')
            ->groupBy('workspace_id')
            ->pluck('total', 'workspace_id');

        return $workspaces
            ->map(function (Workspace $workspace) use ($balances, $memberCounts): array {
                $limits = PlanLimits::for($workspace);
                $plan = $limits->plan();

                return [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'status' => $workspace->status?->value ?? 'active',
                    'suspendedAt' => $workspace->suspended_at?->toIso8601String(),
                    'owner' => $workspace->owner ? [
                        'name' => $workspace->owner->name,
                        'email' => $workspace->owner->email,
                    ] : null,
                    'plan' => $plan?->name,
                    'subscriptionStatus' => $workspace->subscription?->status,
                    'members' => (int) ($memberCounts[$workspace->id] ?? 0),
                    'contactsUsed' => $limits->activeContactsUsed(),
                    'contactsLimit' => $plan?->monthly_active_contacts,
                    'walletPaise' => (int) ($balances[$workspace->id] ?? 0),
                    'createdAt' => $workspace->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * channel_account_id => account name, for labelling webhook rows without
     * tripping the tenant scope (ChannelAccount uses BelongsToWorkspace).
     *
     * @param  array<int, int|string|null>  $ids
     * @return array<int, string>
     */
    public static function channelNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null && $id !== '')));

        if ($ids === []) {
            return [];
        }

        return Tenancy::runUnscoped(fn () => ChannelAccount::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all());
    }
}
