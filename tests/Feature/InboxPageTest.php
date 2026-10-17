<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\ContactChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Proves the tenant inbox page renders for a member, with the shared workspace
 * payload, and stays invisible (404) to outsiders.
 */
class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $member;
    private Conversation $conversation;

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

        Tenancy::run($this->workspace, function () {
            $account = ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Main line', 'external_id' => 'PN1', 'access_token' => 'tok']);
            $contact = Contact::create(['name' => 'Ananya', 'phone' => '+919820044821']);
            ContactChannelIdentity::create([
                'contact_id' => $contact->id,
                'channel_account_id' => $account->id,
                'channel' => 'whatsapp',
                'external_user_id' => '919820044821',
            ]);
            $this->conversation = Conversation::create([
                'contact_id' => $contact->id,
                'channel_account_id' => $account->id,
                'channel' => 'whatsapp',
                'status' => 'open',
                'last_message_preview' => 'Hi, what is the price?',
                'last_message_at' => now(),
                'last_inbound_at' => now(),
            ]);

            Message::create([
                'conversation_id' => $this->conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'contact',
                'type' => 'text',
                'body' => 'Hi, what is the price?',
            ]);
        });
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/inbox')->assertRedirect('/login');
    }

    public function test_non_members_get_a_404(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get('/w/acme/inbox')->assertNotFound();
    }

    public function test_a_member_sees_the_inbox_with_live_tenant_data(): void
    {
        $this->actingAs($this->member)
            ->get('/w/acme/inbox')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Inbox')
                ->where('workspace.slug', 'acme')
                ->where('workspace.role', 'owner')
                ->has('conversations', 1)
                ->where('conversations.0.contact.name', 'Ananya')
                ->where('conversations.0.channel', 'whatsapp')
                ->has('messages', 1)
                ->where('messages.0.body', 'Hi, what is the price?')
                ->has('pipelineStages')
                ->has('cannedResponses')
                ->has('channels', 1)
            );
    }

    public function test_opening_a_thread_clears_its_unread_badge(): void
    {
        Tenancy::run($this->workspace, fn () => $this->conversation->update(['unread_count' => 3]));

        $this->actingAs($this->member)
            ->get('/w/acme/inbox?conversation='.$this->conversation->id)
            ->assertOk();

        $this->assertSame(0, (int) $this->conversation->fresh()->unread_count);
    }

    public function test_a_member_can_leave_an_internal_note(): void
    {
        $this->actingAs($this->member)
            ->postJson("/w/acme/conversations/{$this->conversation->id}/messages", [
                'body' => 'Called her — following up Friday.',
                'note' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'direction' => 'internal',
            'body' => 'Called her — following up Friday.',
            'workspace_id' => $this->workspace->id,
        ]);
    }

    public function test_a_free_text_reply_outside_the_window_is_rejected_with_a_message(): void
    {
        Tenancy::run($this->workspace, fn () => $this->conversation->update(['last_inbound_at' => now()->subDays(3)]));

        $this->actingAs($this->member)
            ->postJson("/w/acme/conversations/{$this->conversation->id}/messages", ['body' => 'Are you still interested?'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'reason'])
            ->assertJsonPath('reason', 'template_required');
    }

    public function test_a_member_cannot_touch_another_workspaces_conversation(): void
    {
        $other = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $this->member->id]);
        $other->users()->attach($this->member->id, ['role' => 'owner']);

        $foreignId = Tenancy::run($other, function () {
            $account = ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Other line', 'external_id' => 'PN2', 'access_token' => 'tok']);
            $contact = Contact::create(['name' => 'Stranger', 'phone' => '+919000000000']);

            return Conversation::create([
                'contact_id' => $contact->id, 'channel_account_id' => $account->id,
                'channel' => 'whatsapp', 'status' => 'open',
            ])->id;
        });

        // The other workspace's conversation id must 404 inside /w/acme (tenant scope),
        // even though this user belongs to both workspaces.
        $this->actingAs($this->member)
            ->postJson("/w/acme/conversations/{$foreignId}/messages", ['body' => 'Cross-tenant'])
            ->assertNotFound();

        $this->assertDatabaseMissing('messages', ['conversation_id' => $foreignId]);
    }
}
