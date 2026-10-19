<?php

namespace Tests\Feature;

use App\Jobs\ProcessMetaWebhook;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Services\Billing\PlanLimits;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The super-admin (platform) console at /admin.
 *
 * The important guarantees: it is invisible to everyone who is not platform
 * staff, it reads REAL cross-tenant data, and its dangerous verbs (suspend,
 * extend, credit) write to the same tables the tenant-facing screens read.
 */
class AdminConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $owner;

    private Workspace $workspace;

    private Subscription $subscription;

    private ChannelAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);

        $this->staff = User::factory()->create(['name' => 'Platform Staff']);
        $this->staff->forceFill(['is_super_admin' => true])->save();

        $this->owner = User::factory()->create(['name' => 'Tenant Owner']);

        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);

        $this->subscription = Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addDays(20),
        ]);

        Wallet::create(['workspace_id' => $this->workspace->id, 'balance_paise' => 100000]);

        $contact = null;

        Tenancy::run($this->workspace, function () use (&$contact) {
            $this->account = ChannelAccount::create([
                'channel' => 'whatsapp', 'name' => 'Main line', 'external_id' => 'PN1',
                'business_account_id' => 'WABA1', 'access_token' => 'tok', 'status' => 'connected',
            ]);

            $contact = Contact::create(['name' => 'Lead', 'phone' => '+919000000001', 'is_opted_in' => true]);
        });

        // A contact only counts toward the monthly quota once it is active.
        PlanLimits::for($this->workspace)->recordActiveContact($contact);
    }

    private function actingAsStaff(): static
    {
        return $this->actingAs($this->staff);
    }

    // ---- Access control -----------------------------------------------------

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_non_staff_users_get_a_404(): void
    {
        $this->actingAs($this->owner)->get('/admin')->assertNotFound();
        $this->actingAs($this->owner)->get('/admin/workspaces')->assertNotFound();
        $this->actingAs($this->owner)->get('/admin/webhooks')->assertNotFound();
    }

    // ---- Overview -----------------------------------------------------------

    public function test_the_overview_reports_platform_totals(): void
    {
        $this->actingAsStaff()
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Dashboard')
                ->where('stats.workspaces.total', 1)
                ->where('stats.workspaces.active', 1)
                ->where('stats.users.staff', 1)
                ->where('stats.mrrPaise', 499900)
                ->where('stats.contacts', 1)
                ->where('stats.walletTotalPaise', 100000)
                ->has('recentWorkspaces', 1)
            );
    }

    // ---- Workspaces ---------------------------------------------------------

    public function test_the_workspace_list_shows_tenants(): void
    {
        $this->actingAsStaff()
            ->get('/admin/workspaces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Workspaces')
                ->has('workspaces.data', 1)
                ->where('workspaces.data.0.slug', 'acme')
                ->where('workspaces.data.0.owner.email', $this->owner->email)
                ->where('workspaces.data.0.plan', 'Growth')
                ->where('workspaces.data.0.walletPaise', 100000)
            );
    }

    public function test_the_workspace_list_can_be_searched_and_filtered(): void
    {
        $beta = Workspace::create(['name' => 'Beta', 'slug' => 'beta', 'owner_id' => $this->owner->id]);
        $beta->forceFill(['status' => 'suspended'])->save();

        $this->actingAsStaff()
            ->get('/admin/workspaces?q=beta')
            ->assertInertia(fn (Assert $page) => $page->has('workspaces.data', 1)->where('workspaces.data.0.slug', 'beta'));

        $this->actingAsStaff()
            ->get('/admin/workspaces?status=suspended')
            ->assertInertia(fn (Assert $page) => $page->has('workspaces.data', 1)->where('workspaces.data.0.slug', 'beta'));
    }

    public function test_the_workspace_detail_exposes_usage_and_team(): void
    {
        $this->actingAsStaff()
            ->get("/admin/workspaces/{$this->workspace->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Workspace')
                ->where('workspace.slug', 'acme')
                ->where('usage.contactsUsed', 1)
                ->where('usage.contactsLimit', 2500)
                ->where('counts.contacts', 1)
                ->where('wallet.balancePaise', 100000)
                ->has('members', 1)
            );
    }

    public function test_a_workspace_can_be_suspended_and_reactivated(): void
    {
        $this->actingAsStaff()
            ->post("/admin/workspaces/{$this->workspace->id}/suspend", ['reason' => 'Non-payment'])
            ->assertRedirect();

        $this->workspace->refresh();
        $this->assertSame('suspended', $this->workspace->status->value);
        $this->assertSame('Non-payment', $this->workspace->suspension_reason);
        $this->assertNotNull($this->workspace->suspended_at);

        $this->actingAsStaff()
            ->post("/admin/workspaces/{$this->workspace->id}/activate")
            ->assertRedirect();

        $this->workspace->refresh();
        $this->assertSame('active', $this->workspace->status->value);
        $this->assertNull($this->workspace->suspension_reason);
        $this->assertNull($this->workspace->suspended_at);
    }

    public function test_extending_adds_days_to_the_paid_period(): void
    {
        $target = $this->subscription->current_period_end->copy()->addDays(10);

        $this->actingAsStaff()
            ->post("/admin/workspaces/{$this->workspace->id}/extend", ['days' => 10])
            ->assertRedirect();

        $this->assertTrue($this->subscription->refresh()->current_period_end->equalTo($target));
    }

    public function test_extending_a_trial_extends_the_trial_clock(): void
    {
        $this->subscription->forceFill([
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(2),
            'current_period_end' => null,
        ])->save();

        $this->actingAsStaff()
            ->post("/admin/workspaces/{$this->workspace->id}/extend", ['days' => 14])
            ->assertRedirect();

        $this->assertTrue($this->subscription->refresh()->trial_ends_at->greaterThan(now()->addDays(15)));
    }

    public function test_extending_validates_the_day_count(): void
    {
        $this->actingAsStaff()
            ->post("/admin/workspaces/{$this->workspace->id}/extend", ['days' => 0])
            ->assertSessionHasErrors('days');
    }

    public function test_limit_overrides_can_be_set_and_cleared(): void
    {
        $this->actingAsStaff()
            ->patch("/admin/workspaces/{$this->workspace->id}/limits", [
                'monthly_active_contacts' => 10000,
                'max_agent_seats' => 25,
            ])
            ->assertRedirect();

        $this->workspace->refresh();
        $this->assertSame(10000, $this->workspace->limits_override['monthly_active_contacts']);
        $this->assertSame(25, $this->workspace->limits_override['max_agent_seats']);
        $this->assertArrayNotHasKey('max_instagram_accounts', $this->workspace->limits_override);

        $this->actingAsStaff()
            ->patch("/admin/workspaces/{$this->workspace->id}/limits", [
                'monthly_active_contacts' => '',
                'max_agent_seats' => '',
                'max_instagram_accounts' => '',
            ])
            ->assertRedirect();

        $this->assertNull($this->workspace->refresh()->limits_override);
    }

    public function test_a_wallet_can_be_credited_with_a_real_ledger_entry(): void
    {
        $this->actingAsStaff()
            ->post("/admin/workspaces/{$this->workspace->id}/credit", [
                'amount' => 500,
                'description' => 'Goodwill',
            ])
            ->assertRedirect();

        $this->assertSame(150000, (int) $this->workspace->wallet->fresh()->balance_paise);

        $tx = WalletTransaction::where('workspace_id', $this->workspace->id)
            ->where('reason', 'adjustment')
            ->firstOrFail();

        $this->assertSame('credit', $tx->type);
        $this->assertSame(50000, (int) $tx->amount_paise);
        $this->assertSame('Goodwill', $tx->description);
    }

    // ---- Plans --------------------------------------------------------------

    public function test_plans_list_with_their_subscriber_count(): void
    {
        $this->actingAsStaff()
            ->get('/admin/plans')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Plans')
                ->has('plans', 1)
                ->where('plans.0.slug', 'growth')
                ->where('plans.0.subscribers', 1)
            );
    }

    public function test_a_plan_can_be_edited(): void
    {
        $plan = Plan::firstOrFail();

        $this->actingAsStaff()
            ->patch("/admin/plans/{$plan->id}", [
                'name' => 'Growth Plus',
                'price' => 6999,
                'monthly_active_contacts' => 4000,
                'max_agent_seats' => 8,
                'max_instagram_accounts' => 6,
                'is_active' => false,
            ])
            ->assertRedirect();

        $plan->refresh();
        $this->assertSame('Growth Plus', $plan->name);
        $this->assertSame(699900, (int) $plan->price_paise);
        $this->assertSame(4000, (int) $plan->monthly_active_contacts);
        $this->assertFalse((bool) $plan->is_active);
    }

    // ---- Webhook event log --------------------------------------------------

    public function test_the_webhook_log_lists_and_filters_events(): void
    {
        WebhookEvent::create([
            'provider' => 'meta', 'external_id' => 'evt-1', 'event_type' => 'messages',
            'channel_account_id' => $this->account->id, 'payload' => ['object' => 'whatsapp_business_account'],
            'status' => 'failed', 'error' => 'boom',
        ]);
        WebhookEvent::create([
            'provider' => 'meta', 'external_id' => 'evt-2', 'event_type' => 'statuses',
            'payload' => ['object' => 'whatsapp_business_account'], 'status' => 'processed',
        ]);

        $this->actingAsStaff()
            ->get('/admin/webhooks')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Webhooks')
                ->has('events.data', 2)
                ->where('counts.failed', 1)
                ->where('counts.processed', 1)
            );

        $this->actingAsStaff()
            ->get('/admin/webhooks?status=failed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.externalId', 'evt-1')
                ->where('events.data.0.retryable', true)
            );
    }

    public function test_a_failed_meta_event_can_be_retried(): void
    {
        Queue::fake();

        $event = WebhookEvent::create([
            'provider' => 'meta', 'external_id' => 'evt-retry', 'event_type' => 'messages',
            'payload' => ['object' => 'whatsapp_business_account'], 'status' => 'failed', 'error' => 'boom',
        ]);

        $this->actingAsStaff()
            ->post("/admin/webhooks/{$event->id}/retry")
            ->assertRedirect();

        $this->assertSame('pending', $event->refresh()->status);
        $this->assertNull($event->error);

        Queue::assertPushed(ProcessMetaWebhook::class, fn (ProcessMetaWebhook $job) => $job->webhookEventId === $event->id);
    }

    public function test_a_processed_event_cannot_be_retried(): void
    {
        $event = WebhookEvent::create([
            'provider' => 'meta', 'external_id' => 'evt-done', 'event_type' => 'messages',
            'payload' => ['object' => 'whatsapp_business_account'], 'status' => 'processed',
        ]);

        $this->actingAsStaff()
            ->post("/admin/webhooks/{$event->id}/retry")
            ->assertStatus(409);
    }

    // ---- The promoting command ---------------------------------------------

    public function test_the_command_grants_and_revokes_staff_access(): void
    {
        $user = User::factory()->create(['email' => 'future-staff@example.com']);

        $this->artisan('omniflow:make-super-admin', ['email' => 'future-staff@example.com'])->assertSuccessful();
        $this->assertTrue((bool) $user->refresh()->is_super_admin);

        $this->artisan('omniflow:make-super-admin', ['email' => 'future-staff@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse((bool) $user->refresh()->is_super_admin);
    }

    public function test_the_command_fails_for_an_unknown_user(): void
    {
        $this->artisan('omniflow:make-super-admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_recent_payments_appear_on_the_workspace_detail(): void
    {
        Tenancy::run($this->workspace, fn () => Payment::create([
            'user_id' => $this->owner->id, 'purpose' => 'wallet_top_up',
            'amount_paise' => 100000, 'gst_paise' => 18000, 'status' => 'captured', 'paid_at' => now(),
        ]));

        $this->actingAsStaff()
            ->get("/admin/workspaces/{$this->workspace->id}")
            ->assertInertia(fn (Assert $page) => $page->has('payments', 1));
    }
}
