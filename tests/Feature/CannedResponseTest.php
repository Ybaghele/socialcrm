<?php

namespace Tests\Feature;

use App\Models\CannedResponse;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick replies are shared workspace assets: any member can create, edit and
 * delete them, but the tenant scope must keep other workspaces' rows invisible.
 */
class CannedResponseTest extends TestCase
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
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);

        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }

    public function test_a_member_can_create_update_and_delete_a_quick_reply(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/canned-responses', [
                'title' => 'Shipping time',
                'body' => 'Orders usually ship within 2 business days.',
                'shortcut' => 'shipping',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('canned_responses', [
            'workspace_id' => $this->workspace->id,
            'created_by' => $this->member->id,
            'title' => 'Shipping time',
            'shortcut' => '/shipping',
        ]);

        $response = Tenancy::run($this->workspace, fn () => CannedResponse::sole());

        $this->actingAs($this->member)
            ->patch("/w/acme/canned-responses/{$response->id}", [
                'title' => 'Shipping update',
                'body' => 'Orders ship within 3 business days.',
                'shortcut' => '/ship',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('canned_responses', [
            'id' => $response->id,
            'title' => 'Shipping update',
            'shortcut' => '/ship',
        ]);

        $this->actingAs($this->member)
            ->delete("/w/acme/canned-responses/{$response->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('canned_responses', ['id' => $response->id]);
    }

    public function test_quick_reply_fields_are_validated(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/canned-responses', [
                'title' => '',
                'body' => '',
                'shortcut' => 'shipping time',
            ])
            ->assertSessionHasErrors(['title', 'body', 'shortcut']);

        Tenancy::run($this->workspace, fn () => $this->assertSame(0, CannedResponse::count()));
    }

    public function test_another_workspaces_quick_reply_cannot_be_changed(): void
    {
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->member->id]);

        $foreignId = Tenancy::run($other, fn () => CannedResponse::create([
            'title' => 'Foreign reply',
            'body' => 'Not yours.',
        ])->id);

        $this->actingAs($this->member)
            ->patch("/w/acme/canned-responses/{$foreignId}", [
                'title' => 'Hijacked',
                'body' => 'Hijacked.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('canned_responses', [
            'id' => $foreignId,
            'title' => 'Foreign reply',
        ]);
    }
}
