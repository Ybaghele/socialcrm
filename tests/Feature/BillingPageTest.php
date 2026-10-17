<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The Billing screen: reads plan/quota/wallet from the real tables and credits
 * the wallet through the append-only ledger.
 */
class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();

        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->member->id]);
        $this->workspace->users()->attach($this->member->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth Plan', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
            'overage_price_paise' => 150000, 'overage_block_size' => 500, 'gst_percent' => 18,
        ]);

        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(30),
        ]);

        Wallet::create(['workspace_id' => $this->workspace->id, 'balance_paise' => 0]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/billing')->assertRedirect('/login');
    }

    public function test_non_members_get_a_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/w/acme/billing')->assertNotFound();
    }

    public function test_a_member_sees_the_plan_quota_and_wallet(): void
    {
        $this->actingAs($this->member)
            ->get('/w/acme/billing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Billing')
                ->where('plan.name', 'Growth Plan')
                ->where('plan.pricePaise', 499900)
                ->where('plan.gstPercent', 18)
                ->where('subscription.status', 'trialing')
                ->where('subscription.onTrial', true)
                ->where('usage.used', 0)
                ->where('usage.limit', 2500)
                ->where('usage.percent', 0)
                ->where('usage.overageBlocks', 0)
                ->where('addons.instagram.max', 5)
                ->where('addons.seats.used', 1)
                ->where('wallet.balancePaise', 0)
                ->where('gateway.configured', false)
                ->where('topUpPresets', [1000, 2500, 5000])
                ->has('transactions', 0)
                ->has('payments', 0)
            );
    }

    public function test_a_top_up_captures_a_payment_and_credits_the_wallet_ledger(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/billing/top-up', ['amount' => 2500])
            ->assertRedirect()
            ->assertSessionHas('success');

        $wallet = Wallet::where('workspace_id', $this->workspace->id)->firstOrFail();
        $this->assertSame(250000, $wallet->balance_paise);
        $this->assertSame(2840, $wallet->estimated_messages); // 250000 / 88

        $tx = WalletTransaction::where('workspace_id', $this->workspace->id)->firstOrFail();
        $this->assertSame('credit', $tx->type);
        $this->assertSame('top_up', $tx->reason);
        $this->assertSame(250000, (int) $tx->amount_paise);
        $this->assertSame(250000, (int) $tx->balance_after_paise);

        $payment = Tenancy::run($this->workspace, fn () => Payment::firstOrFail());
        $this->assertSame('wallet_top_up', $payment->purpose);
        $this->assertSame('captured', $payment->status);
        $this->assertSame(45000, (int) $payment->gst_paise); // 18% of 250000
        $this->assertNotNull($payment->invoice_number);
        // The ledger line points back at the payment.
        $this->assertSame($payment->getMorphClass(), $tx->reference_type);
        $this->assertSame($payment->id, (int) $tx->reference_id);
    }

    public function test_a_top_up_below_the_minimum_is_rejected(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/billing/top-up', ['amount' => 50])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Wallet::where('workspace_id', $this->workspace->id)->value('balance_paise'));
    }

    public function test_the_manual_top_up_route_is_disabled_once_a_gateway_is_configured(): void
    {
        config(['services.razorpay.key' => 'rzp_test_123']);

        $this->actingAs($this->member)
            ->post('/w/acme/billing/top-up', ['amount' => 2500])
            ->assertStatus(501);

        $this->assertSame(0, Wallet::where('workspace_id', $this->workspace->id)->value('balance_paise'));
    }
}
