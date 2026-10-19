<?php

namespace App\Services\Billing;

use App\Enums\ChannelType;
use App\Models\Contact;
use App\Models\Plan;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * One place that answers "is this tenant allowed to do X on their plan?".
 * Per-tenant enterprise overrides live in workspaces.limits_override.
 */
final class PlanLimits
{
    private function __construct(private Workspace $workspace)
    {
    }

    public static function for(Workspace $workspace): self
    {
        return new self($workspace);
    }

    public function plan(): ?Plan
    {
        return $this->workspace->subscription?->plan;
    }

    private function limit(string $key): ?int
    {
        $value = $this->workspace->limits_override[$key] ?? $this->plan()?->{$key};

        return $value === null ? null : (int) $value;
    }

    // ---- Agent seats -------------------------------------------------------

    public function seatsUsed(): int
    {
        $members = DB::table('workspace_user')->where('workspace_id', $this->workspace->id)->count();
        $pending = $this->workspace->invitations()
            ->whereNull('accepted_at')->where('expires_at', '>', now())->count();

        return $members + $pending;
    }

    public function canAddSeat(): bool
    {
        $max = $this->limit('max_agent_seats');

        return $max !== null && $this->seatsUsed() < $max;
    }

    /** The plan's agent-seat cap (null = unlimited), for the Team screen. */
    public function seatLimit(): ?int
    {
        return $this->limit('max_agent_seats');
    }

    // ---- Channel accounts --------------------------------------------------

    public function canConnect(ChannelType $channel): bool
    {
        if ($channel !== ChannelType::Instagram) {
            return true; // the plan only caps Instagram accounts today
        }

        $max = $this->limit('max_instagram_accounts');
        $used = Tenancy::run($this->workspace, fn () => $this->workspace->channelAccounts()
            ->where('channel', ChannelType::Instagram->value)->count());

        return $max !== null && $used < $max;
    }

    // ---- Monthly active contacts (MAC) -------------------------------------

    public function periodStart(): CarbonInterface
    {
        $start = $this->workspace->subscription?->current_period_start ?? now()->startOfMonth();

        return $start->copy()->startOfDay();
    }

    /** Idempotent: a contact counts once per billing period. True if newly counted. */
    public function recordActiveContact(Contact $contact): bool
    {
        return DB::table('monthly_active_contacts')->insertOrIgnore([
            'workspace_id' => $this->workspace->id,
            'contact_id' => $contact->id,
            'period_start' => $this->periodStart()->toDateString(),
            'first_active_at' => now(),
        ]) === 1;
    }

    public function activeContactsUsed(): int
    {
        return DB::table('monthly_active_contacts')
            ->where('workspace_id', $this->workspace->id)
            ->where('period_start', $this->periodStart()->toDateString())
            ->count();
    }

    /** Over quota is billed (₹ per block), not blocked, so inbound leads are never dropped. */
    public function overageBlocks(): int
    {
        $allowed = $this->limit('monthly_active_contacts') ?? 0;
        $block = max(1, (int) ($this->plan()?->overage_block_size ?? 500));
        $over = $this->activeContactsUsed() - $allowed;

        return $over > 0 ? (int) ceil($over / $block) : 0;
    }
}
