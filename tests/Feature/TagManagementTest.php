<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tags are a shared workspace vocabulary. Members can manage the list and
 * assign tags to a contact; tenant scoping keeps foreign tags and contacts out.
 */
class TagManagementTest extends TestCase
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

    public function test_a_member_can_create_update_and_delete_a_tag(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/tags', ['name' => '#VIP', 'color' => '#10B981'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tags', [
            'workspace_id' => $this->workspace->id,
            'name' => 'VIP',
            'color' => '#10b981',
        ]);

        $tag = Tenancy::run($this->workspace, fn () => Tag::sole());

        $this->actingAs($this->member)
            ->patch("/w/acme/tags/{$tag->id}", ['name' => 'Priority', 'color' => '#F59E0B'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'Priority',
            'color' => '#f59e0b',
        ]);

        $this->actingAs($this->member)
            ->delete("/w/acme/tags/{$tag->id}")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_tag_names_are_unique_within_a_workspace(): void
    {
        Tenancy::run($this->workspace, fn () => Tag::create(['name' => 'VIP']));

        $this->actingAs($this->member)
            ->post('/w/acme/tags', ['name' => '#VIP'])
            ->assertSessionHasErrors('name');

        Tenancy::run($this->workspace, fn () => $this->assertSame(1, Tag::count()));
    }

    public function test_a_tag_name_must_be_a_string(): void
    {
        $this->actingAs($this->member)
            ->post('/w/acme/tags', ['name' => ['VIP']])
            ->assertSessionHasErrors('name');

        Tenancy::run($this->workspace, fn () => $this->assertSame(0, Tag::count()));
    }

    public function test_a_member_can_assign_and_remove_tags_on_a_contact(): void
    {
        [$contact, $vip, $priority] = Tenancy::run($this->workspace, function () {
            $contact = Contact::create(['name' => 'Ananya', 'phone' => '+919820044821']);
            $vip = Tag::create(['name' => 'VIP', 'color' => '#10b981']);
            $priority = Tag::create(['name' => 'Priority', 'color' => '#f59e0b']);

            return [$contact, $vip, $priority];
        });

        $this->actingAs($this->member)
            ->patch("/w/acme/contacts/{$contact->id}/tags", [
                'tag_ids' => [$vip->id, $priority->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $vip->id,
            'applied_by' => 'user',
        ]);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $priority->id,
            'applied_by' => 'user',
        ]);

        $this->actingAs($this->member)
            ->patch("/w/acme/contacts/{$contact->id}/tags", [
                'tag_ids' => [$priority->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $vip->id,
        ]);
        $this->assertDatabaseHas('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $priority->id,
        ]);
    }

    public function test_tags_from_another_workspace_cannot_be_assigned(): void
    {
        $contact = Tenancy::run($this->workspace, fn () => Contact::create(['name' => 'Ananya']));

        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->member->id]);
        $foreignTagId = Tenancy::run($other, fn () => Tag::create(['name' => 'Foreign'])->id);

        $this->actingAs($this->member)
            ->patch("/w/acme/contacts/{$contact->id}/tags", [
                'tag_ids' => [$foreignTagId],
            ])
            ->assertSessionHasErrors('tag_ids.0');

        $this->assertDatabaseMissing('contact_tag', [
            'contact_id' => $contact->id,
            'tag_id' => $foreignTagId,
        ]);
    }

    public function test_another_workspaces_contact_cannot_be_tagged(): void
    {
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->member->id]);
        $foreignContactId = Tenancy::run($other, fn () => Contact::create(['name' => 'Stranger'])->id);

        $this->actingAs($this->member)
            ->patch("/w/acme/contacts/{$foreignContactId}/tags", ['tag_ids' => []])
            ->assertNotFound();
    }
}
