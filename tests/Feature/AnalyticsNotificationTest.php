<?php

namespace Tests\Feature;

use App\Actions\AcceptInvitation;
use App\Models\Campaign;
use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceNotification;
use App\Services\Billing\WalletService;
use App\Services\Meta\TemplateSync;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The notification centre (bell + list + read state) and the Analytics screen.
 *
 * The interesting behaviour is the fan-out: operational notices must reach owners
 * and admins but never agents, the wallet warning must fire once per crossing, and
 * a notification must never be readable by anyone but its owner.
 */
class AnalyticsNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private User $owner;
    private User $admin;
    private User $agent;
    private ChannelAccount $account;
    private MessageTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync', 'omniflow.meta.graph_version' => 'v22.0']);
        // Scoped to the send endpoint: Http::fake() appends stubs, so a broader
        // pattern here would shadow the template-fetch fake in later tests.
        Http::fake(['graph.facebook.com/*/messages*' => Http::response(['messages' => [['id' => 'wamid.N1']]], 200)]);

        $this->owner = User::factory()->create(['name' => 'Owner']);
        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->agent = User::factory()->create(['name' => 'Agent']);

        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->workspace->users()->attach($this->admin->id, ['role' => 'admin']);
        $this->workspace->users()->attach($this->agent->id, ['role' => 'agent']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 5, 'max_agent_seats' => 5,
        ]);
        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);
        Wallet::create(['workspace_id' => $this->workspace->id, 'balance_paise' => 100000]);

        Tenancy::run($this->workspace, function () {
            $this->account = ChannelAccount::create([
                'channel' => 'whatsapp', 'name' => 'Main line', 'external_id' => 'PN1',
                'business_account_id' => 'WABA1', 'access_token' => 'tok', 'status' => 'connected',
            ]);

            $this->template = MessageTemplate::create([
                'channel_account_id' => $this->account->id,
                'name' => 'diwali_festive_offer_2026', 'language' => 'en',
                'category' => 'marketing', 'status' => 'approved',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy our festive offer!']],
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function contact(array $attributes = []): Contact
    {
        return Tenancy::run($this->workspace, fn () => Contact::create(array_merge([
            'name' => 'Lead', 'phone' => '+919000000001', 'is_opted_in' => true,
        ], $attributes)));
    }

    private function conversation(Contact $contact): Conversation
    {
        return Tenancy::run($this->workspace, fn () => Conversation::create([
            'contact_id' => $contact->id,
            'channel_account_id' => $this->account->id,
            'channel' => 'whatsapp',
            'status' => 'open',
        ]));
    }

    /** @param array<string, mixed> $overrides */
    private function message(Conversation $conversation, string $direction, array $overrides = []): Message
    {
        return Tenancy::run($this->workspace, fn () => Message::create(array_merge([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'sender_type' => $direction === 'inbound' ? 'contact' : 'user',
            'type' => 'text',
            'body' => 'hello there',
            'status' => 'sent',
        ], $overrides)));
    }

    private function broadcast(): Campaign
    {
        return Tenancy::run($this->workspace, fn () => Campaign::create([
            'name' => 'Blast', 'type' => 'broadcast', 'channel' => 'whatsapp', 'status' => 'active',
            'channel_account_id' => $this->account->id, 'message_template_id' => $this->template->id,
            'template_variables' => ['{{first_name}}'], 'started_at' => now(),
            'created_by' => $this->owner->id,
        ]));
    }

    private function notify(string $title, bool $read = false, ?User $user = null): WorkspaceNotification
    {
        return WorkspaceNotification::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => ($user ?? $this->owner)->id,
            'type' => 'test',
            'level' => 'info',
            'title' => $title,
            'read_at' => $read ? now() : null,
        ]);
    }

    // ---- Fan-out -----------------------------------------------------------

    public function test_a_finished_broadcast_notifies_owners_and_admins(): void
    {
        $this->contact();

        $campaign = $this->broadcast();

        $this->artisan('campaigns:process')->assertSuccessful();

        $this->assertSame('completed', $campaign->refresh()->status);

        foreach ([$this->owner, $this->admin] as $manager) {
            $this->assertSame(
                1,
                WorkspaceNotification::where('user_id', $manager->id)->where('type', 'campaign_completed')->count(),
            );
        }

        $this->assertSame(0, WorkspaceNotification::where('user_id', $this->agent->id)->count());
    }

    public function test_a_debit_that_crosses_the_floor_warns_the_managers_once(): void
    {
        // ₹51 in the wallet; a ₹2 debit crosses the ₹50 floor.
        Tenancy::run($this->workspace, fn () => Wallet::where('workspace_id', $this->workspace->id)
            ->update(['balance_paise' => 5100]));

        Tenancy::run($this->workspace, fn () => app(WalletService::class)->debit($this->workspace, 200, 'template_message'));

        $this->assertSame(2, WorkspaceNotification::where('type', 'wallet_low')->count());

        // Already under the floor — a second debit must stay quiet.
        Tenancy::run($this->workspace, fn () => app(WalletService::class)->debit($this->workspace, 200, 'template_message'));

        $this->assertSame(2, WorkspaceNotification::where('type', 'wallet_low')->count());
    }

    public function test_a_template_approval_notifies_the_managers(): void
    {
        $pending = Tenancy::run($this->workspace, fn () => MessageTemplate::create([
            'channel_account_id' => $this->account->id,
            'name' => 'festive_offer', 'language' => 'en', 'category' => 'marketing',
            'status' => 'pending',
            'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']],
        ]));

        Http::fake([
            'graph.facebook.com/*/message_templates*' => Http::response([
                'data' => [[
                    'id' => '123', 'name' => 'festive_offer', 'status' => 'APPROVED',
                    'category' => 'MARKETING', 'language' => 'en',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']],
                ]],
            ], 200),
        ]);

        $result = Tenancy::run($this->workspace, fn () => app(TemplateSync::class)->sync($this->workspace));

        $this->assertSame(1, $result['updated']);
        $this->assertSame('approved', $pending->refresh()->status);

        foreach ([$this->owner, $this->admin] as $manager) {
            $this->assertSame(
                1,
                WorkspaceNotification::where('user_id', $manager->id)->where('type', 'template_approved')->count(),
            );
        }
    }

    public function test_the_owner_is_told_when_an_invitation_is_accepted(): void
    {
        $invitee = User::factory()->create(['email' => 'newhire@example.com', 'name' => 'New Hire']);
        $plain = 'invite-'.str_repeat('a', 40);

        WorkspaceInvitation::create([
            'workspace_id' => $this->workspace->id,
            'email' => 'newhire@example.com',
            'role' => 'agent',
            'token' => hash('sha256', $plain),
            'invited_by' => $this->owner->id,
            'expires_at' => now()->addDays(7),
        ]);

        app(AcceptInvitation::class)->handle($invitee, $plain);

        $this->assertSame(
            1,
            WorkspaceNotification::where('user_id', $this->owner->id)->where('type', 'member_joined')->count(),
        );
        $this->assertSame(0, WorkspaceNotification::where('user_id', $this->admin->id)->count());
    }

    // ---- Reading -----------------------------------------------------------

    public function test_the_header_shares_the_unread_count(): void
    {
        $this->notify('Fresh');
        $this->notify('Already seen', read: true);

        $this->actingAs($this->owner)
            ->get('/w/acme/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread', 1)
                ->has('notifications.items', 2)
            );
    }

    public function test_a_notification_can_be_marked_read(): void
    {
        $notification = $this->notify('Read me');

        $this->actingAs($this->owner)
            ->post("/w/acme/notifications/{$notification->id}/read")
            ->assertRedirect();

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_all_notifications_can_be_marked_read(): void
    {
        $this->notify('One');
        $this->notify('Two');

        $this->actingAs($this->owner)
            ->post('/w/acme/notifications/read-all')
            ->assertRedirect();

        $this->assertSame(
            0,
            WorkspaceNotification::where('user_id', $this->owner->id)->whereNull('read_at')->count(),
        );
    }

    public function test_another_users_notification_is_not_reachable(): void
    {
        $notification = $this->notify('Private', user: $this->admin);

        $this->actingAs($this->owner)
            ->post("/w/acme/notifications/{$notification->id}/read")
            ->assertNotFound();

        $this->assertNull($notification->refresh()->read_at);
    }

    public function test_the_notification_list_screen_renders(): void
    {
        $this->notify('Hello there');

        $this->actingAs($this->owner)
            ->get('/w/acme/notifications')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Notifications')
                ->has('notifications', 1)
                ->where('unreadCount', 1)
            );
    }

    // ---- Analytics ---------------------------------------------------------

    public function test_analytics_counts_messages_audience_and_spend(): void
    {
        $conversation = $this->conversation($this->contact());

        $this->message($conversation, 'inbound');
        $this->message($conversation, 'inbound');
        $this->message($conversation, 'inbound');
        $this->message($conversation, 'outbound');

        $campaign = $this->broadcast();
        $this->message($conversation, 'outbound', ['campaign_id' => $campaign->id, 'cost_paise' => 88]);

        Tenancy::run($this->workspace, fn () => app(WalletService::class)->debit(
            $this->workspace, 8800, 'template_message', null, 'test spend',
        ));

        $this->actingAs($this->owner)
            ->get('/w/acme/analytics')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analytics')
                ->where('range', 30)
                ->where('totals.inbound', 3)
                ->where('totals.outbound', 2)
                ->where('totals.broadcastSends', 1)
                ->where('totals.conversations', 1)
                ->where('totals.newContacts', 1)
                ->where('totals.optedIn', 1)
                ->where('totals.spendPaise', 8800)
                ->has('series', 30)
                ->where('series.29.inbound', 3)
                ->has('campaigns', 1)
            );
    }

    public function test_the_analytics_window_can_be_narrowed(): void
    {
        $this->actingAs($this->owner)
            ->get('/w/acme/analytics?range=7')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('range', 7)
                ->has('series', 7)
            );
    }

    public function test_an_unknown_analytics_window_falls_back_to_the_default(): void
    {
        $this->actingAs($this->owner)
            ->get('/w/acme/analytics?range=1000')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('range', 30));
    }
}
