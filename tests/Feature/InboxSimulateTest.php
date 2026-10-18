<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The inbound simulator writes real conversations into the tenant, so on a
 * non-local environment it must be owner-only. Locally (and in these tests,
 * which run as "testing") it would also be reachable, so the assertions here
 * focus on the authorization boundary that production relies on.
 */
class InboxSimulateTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'owner_id' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'price_paise' => 99900,
            'monthly_active_contacts' => 5000,
            'max_instagram_accounts' => 5,
            'max_agent_seats' => 5,
        ]);

        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_an_owner_can_simulate_an_inbound_message(): void
    {
        $this->actingAs($this->owner)
            ->post('/w/acme/inbox/simulate', ['text' => 'Owner test message'])
            ->assertRedirect();

        $this->assertSame(1, Tenancy::run($this->workspace, fn () => Conversation::count()));
        $this->assertSame('Owner test message', Tenancy::run($this->workspace, fn () => Message::query()->firstOrFail()->body));
    }

    public function test_an_agent_cannot_simulate_an_inbound_message(): void
    {
        $agent = User::factory()->create();
        $this->workspace->users()->attach($agent->id, ['role' => 'agent']);

        $this->actingAs($agent)
            ->post('/w/acme/inbox/simulate', ['text' => 'Agent test message'])
            ->assertNotFound();

        $this->assertSame(0, Tenancy::run($this->workspace, fn () => Conversation::count()));
    }

    public function test_a_non_member_cannot_simulate_an_inbound_message(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post('/w/acme/inbox/simulate', ['text' => 'Outsider'])
            ->assertNotFound();

        $this->assertSame(0, Tenancy::run($this->workspace, fn () => Conversation::count()));
    }
}
