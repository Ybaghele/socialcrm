<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /w/{workspace}/contacts — the "Add Contact" modal in the Lead CRM.
 */
class ContactsCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'owner_id' => $this->user->id,
        ]);
        $this->workspace->users()->attach($this->user->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);

        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_authenticated_member_can_create_contact(): void
    {
        $stage = Tenancy::run($this->workspace, fn () => $this->workspace->pipelineStages()->create([
            'name' => 'New Lead',
            'position' => 1,
            'color' => '#64748b',
        ]));

        $response = $this->actingAs($this->user)
            ->post("/w/{$this->workspace->slug}/contacts", [
                'name' => 'John Doe',
                'phone' => '+919876543210',
                'email' => 'john@example.com',
                'pipeline_stage_id' => $stage->id,
                'is_opted_in' => true,
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $this->workspace->id,
            'name' => 'John Doe',
            'phone' => '+919876543210',
            'email' => 'john@example.com',
            'pipeline_stage_id' => $stage->id,
        ]);
    }

    public function test_name_is_required(): void
    {
        $this->actingAs($this->user)
            ->post("/w/{$this->workspace->slug}/contacts", ['phone' => '+910000000000'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_guest_cannot_create_contact(): void
    {
        $this->post("/w/{$this->workspace->slug}/contacts", [
            'name' => 'Jane',
        ])->assertRedirect('/login');

        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_non_member_cannot_create_contact(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->post("/w/{$this->workspace->slug}/contacts", [
                'name' => 'Jane',
            ])->assertNotFound();

        $this->assertDatabaseCount('contacts', 0);
    }
}
