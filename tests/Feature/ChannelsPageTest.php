<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Settings → Channels: the screen that registers the Meta asset ids the shared
 * webhook routes inbound traffic to, plus token refresh and disconnect/delete.
 */
class ChannelsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'omniflow.meta.app_secret' => 'test-secret',
            'omniflow.meta.verify_token' => 'verify-me',
            'omniflow.meta.graph_version' => 'v22.0',
            'omniflow.meta.hosts.whatsapp' => 'https://graph.facebook.com',
        ]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->owner->id]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth', 'slug' => 'growth', 'price_paise' => 499900,
            'monthly_active_contacts' => 2500, 'max_instagram_accounts' => 1, 'max_agent_seats' => 5,
        ]);

        Subscription::create(['workspace_id' => $this->workspace->id, 'plan_id' => $plan->id, 'status' => 'active']);
    }

    private function connect(array $attributes = []): ChannelAccount
    {
        return Tenancy::run($this->workspace, fn () => ChannelAccount::create(array_merge([
            'channel' => 'whatsapp',
            'name' => 'Support line',
            'external_id' => 'PN1',
            'access_token' => 'tok',
        ], $attributes)));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/channels')->assertRedirect('/login');
    }

    public function test_non_members_get_a_404(): void
    {
        $this->actingAs(User::factory()->create())->get('/w/acme/channels')->assertNotFound();
    }

    public function test_a_member_sees_accounts_and_the_webhook_configuration(): void
    {
        $this->connect();

        $this->actingAs($this->owner)
            ->get('/w/acme/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Channels')
                ->has('accounts', 1)
                ->where('accounts.0.channel', 'whatsapp')
                ->where('accounts.0.externalId', 'PN1')
                ->where('accounts.0.status', 'connected')
                ->where('accounts.0.hasToken', true)
                // The owner doubles as the Meta app operator, so the platform token is revealed.
                ->where('webhook.verifyToken', 'verify-me')
                ->where('webhook.graphVersion', 'v22.0')
                ->where('webhook.appSecretConfigured', true)
                // channelOptions must not clobber the layout's shared "channels" badges.
                ->has('channelOptions', 3)
                ->where('channelOptions.0.value', 'whatsapp')
                ->where('channelOptions.0.canConnect', true)
                ->where('channelOptions.1.value', 'instagram')
                ->where('channelOptions.1.max', 1)
                ->where('channelOptions.1.canConnect', true)
                // Sanity: the layout's own shared "channels" badge prop is untouched.
                ->where('channels.0.channel', 'whatsapp')
                ->where('channels.0.name', 'Support line')
                ->where('webhook.callbackUrl', route('webhooks.meta.receive'))
            );
    }

    public function test_an_agent_does_not_receive_the_platform_verify_token(): void
    {
        $agent = User::factory()->create();
        $this->workspace->users()->attach($agent->id, ['role' => 'agent']);

        $this->actingAs($agent)
            ->get('/w/acme/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('webhook.verifyToken', null));
    }

    public function test_store_connects_an_account_and_encrypts_the_token(): void
    {
        $this->actingAs($this->owner)
            ->post('/w/acme/channels', [
                'channel' => 'whatsapp',
                'name' => 'Support line',
                'external_id' => '123456789012345',
                'handle' => '+919820044821',
                'business_account_id' => 'WABA1',
                'access_token' => 'super-secret-token',
                'token_expires_at' => now()->addYear()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->workspace->id,
            'channel' => 'whatsapp',
            'external_id' => '123456789012345',
            'status' => 'connected',
        ]);

        // The column must not hold the plaintext, but the model must decrypt it back.
        $raw = DB::table('channel_accounts')->where('external_id', '123456789012345')->value('access_token');
        $this->assertNotSame('super-secret-token', $raw);

        $token = Tenancy::run($this->workspace, fn () => ChannelAccount::where('external_id', '123456789012345')->value('access_token'));
        $this->assertSame('super-secret-token', $token);
    }

    public function test_store_rejects_an_account_id_already_claimed_by_another_workspace(): void
    {
        $other = Workspace::create(['name' => 'Globex', 'slug' => 'globex', 'owner_id' => User::factory()->create()->id]);
        Tenancy::run($other, fn () => ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Theirs', 'external_id' => 'SHARED1']));

        $this->actingAs($this->owner)
            ->post('/w/acme/channels', ['channel' => 'whatsapp', 'name' => 'Mine', 'external_id' => 'SHARED1'])
            ->assertSessionHasErrors('external_id');

        $this->assertSame(0, Tenancy::run($this->workspace, fn () => ChannelAccount::count()));
    }

    public function test_store_enforces_the_instagram_plan_limit(): void
    {
        Tenancy::run($this->workspace, fn () => ChannelAccount::create(['channel' => 'instagram', 'name' => 'First', 'external_id' => 'IG1']));

        $this->actingAs($this->owner)
            ->post('/w/acme/channels', ['channel' => 'instagram', 'name' => 'Second', 'external_id' => 'IG2'])
            ->assertSessionHasErrors('channel');

        $this->assertSame(1, Tenancy::run($this->workspace, fn () => ChannelAccount::where('channel', 'instagram')->count()));
    }

    public function test_update_can_disconnect_and_reconnect(): void
    {
        $account = $this->connect();

        $this->actingAs($this->owner)
            ->patch("/w/acme/channels/{$account->id}", ['status' => 'disconnected'])
            ->assertRedirect();

        $this->assertSame('disconnected', $account->fresh()->status);

        $this->actingAs($this->owner)
            ->patch("/w/acme/channels/{$account->id}", ['status' => 'connected'])
            ->assertRedirect();

        $this->assertSame('connected', $account->fresh()->status);
    }

    public function test_a_corrupt_stored_token_does_not_break_the_channel_list(): void
    {
        $account = $this->connect();

        // Simulates a row whose encrypted value was truncated or hand-edited in the DB.
        DB::table('channel_accounts')->where('id', $account->id)->update(['access_token' => 'not-a-valid-payload']);

        $this->actingAs($this->owner)
            ->get('/w/acme/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('accounts', 1)
                ->where('accounts.0.hasToken', false));
    }

    public function test_update_saves_the_business_account_id(): void
    {
        $account = $this->connect();

        $this->actingAs($this->owner)
            ->patch("/w/acme/channels/{$account->id}", ['business_account_id' => " WABA123 \n"])
            ->assertRedirect();

        $this->assertSame('WABA123', $account->fresh()->business_account_id);

        $this->actingAs($this->owner)
            ->patch("/w/acme/channels/{$account->id}", ['business_account_id' => ''])
            ->assertRedirect();

        $this->assertNull($account->fresh()->business_account_id);
    }

    public function test_a_member_cannot_touch_another_workspaces_account(): void
    {
        $other = Workspace::create(['name' => 'Globex', 'slug' => 'globex', 'owner_id' => User::factory()->create()->id]);
        $theirs = Tenancy::run($other, fn () => ChannelAccount::create(['channel' => 'whatsapp', 'name' => 'Theirs', 'external_id' => 'PN9']));

        $this->actingAs($this->owner)
            ->patch("/w/acme/channels/{$theirs->id}", ['status' => 'disconnected'])
            ->assertNotFound();

        $this->assertSame('connected', $theirs->fresh()->status);
    }

    public function test_verify_marks_the_account_reachable_when_meta_accepts(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['verified_name' => 'Acme', 'quality_rating' => 'GREEN'], 200)]);

        $account = $this->connect();

        $this->actingAs($this->owner)
            ->post("/w/acme/channels/{$account->id}/verify")
            ->assertRedirect();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v22.0/PN1'));

        $account->refresh();
        $this->assertSame('connected', $account->status);
        $this->assertSame('Acme', $account->meta['profile']['verified_name'] ?? null);
    }

    public function test_store_strips_whitespace_quotes_and_a_bearer_prefix_from_pasted_credentials(): void
    {
        $this->actingAs($this->owner)
            ->post('/w/acme/channels', [
                'channel' => 'whatsapp',
                'name' => 'Support line',
                // Exactly what a copy from Meta's dashboard/curl snippet looks like.
                'external_id' => " 123 456 \n",
                'access_token' => "  \"Bearer EAAabc123\"  \n",
            ])
            ->assertRedirect();

        // Whitespace is stripped before validation, so the id lands clean in the DB.
        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->workspace->id,
            'external_id' => '123456',
        ]);

        $token = Tenancy::run($this->workspace, fn () => ChannelAccount::where('external_id', '123456')->value('access_token'));
        $this->assertSame('EAAabc123', $token);
    }

    public function test_store_rejects_an_id_that_only_differs_by_whitespace_from_a_connected_one(): void
    {
        $this->actingAs($this->owner)
            ->post('/w/acme/channels', ['channel' => 'whatsapp', 'name' => 'First', 'external_id' => 'PN1'])
            ->assertRedirect();

        // Normalisation happens before the unique rule, so this is a duplicate, not a 500.
        $this->actingAs($this->owner)
            ->post('/w/acme/channels', ['channel' => 'whatsapp', 'name' => 'Second', 'external_id' => " PN1 \n"])
            ->assertSessionHasErrors('external_id');

        $this->assertSame(1, Tenancy::run($this->workspace, fn () => ChannelAccount::count()));
    }

    public function test_update_strips_a_bearer_prefix_from_a_replaced_token(): void
    {
        $account = $this->connect();

        $this->actingAs($this->owner)
            ->patch("/w/acme/channels/{$account->id}", ['access_token' => 'Bearer EAAdirty'])
            ->assertRedirect();

        $this->assertSame('EAAdirty', $account->fresh()->access_token);
    }

    public function test_verify_self_heals_a_token_stored_with_stray_whitespace(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['verified_name' => 'Acme'], 200)]);

        // Simulates a row saved before the input was normalised.
        $account = $this->connect(['access_token' => " EAAabc123 \n"]);

        $this->actingAs($this->owner)
            ->post("/w/acme/channels/{$account->id}/verify")
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer EAAabc123'));
        $this->assertSame('EAAabc123', $account->fresh()->access_token);
    }

    public function test_verify_explains_a_malformed_token_instead_of_dumping_meta_jargon(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token - Cannot parse access token'],
        ], 400)]);

        $account = $this->connect();

        $this->actingAs($this->owner)
            ->post("/w/acme/channels/{$account->id}/verify")
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'could not parse the access token')
                && str_contains($message, 'EAA'));

        $this->assertSame('error', $account->fresh()->status);
    }

    public function test_verify_flags_the_account_when_meta_rejects_the_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);

        $account = $this->connect();

        $this->actingAs($this->owner)
            ->post("/w/acme/channels/{$account->id}/verify")
            ->assertRedirect();

        // A bad token only breaks sending, never inbound, so the account stays visible but flagged.
        $this->assertSame('error', $account->fresh()->status);
    }

    public function test_verify_without_a_token_is_refused(): void
    {
        $account = $this->connect();
        Tenancy::run($this->workspace, fn () => $account->forceFill(['access_token' => null])->save());

        Http::fake();

        $this->actingAs($this->owner)
            ->post("/w/acme/channels/{$account->id}/verify")
            ->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_verify_does_not_reconnect_a_disconnected_account(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['verified_name' => 'Acme'], 200)]);

        $account = $this->connect();
        Tenancy::run($this->workspace, fn () => $account->forceFill(['status' => 'disconnected'])->save());

        $this->actingAs($this->owner)
            ->post("/w/acme/channels/{$account->id}/verify")
            ->assertRedirect();

        $this->assertSame('disconnected', $account->fresh()->status);
    }

    public function test_destroy_refuses_an_account_with_conversation_history(): void
    {
        $account = $this->connect();

        Tenancy::run($this->workspace, function () use ($account) {
            $contact = Contact::create(['name' => 'Ananya', 'phone' => '+919820044821']);
            Conversation::create(['contact_id' => $contact->id, 'channel_account_id' => $account->id, 'channel' => 'whatsapp']);
        });

        $this->actingAs($this->owner)
            ->delete("/w/acme/channels/{$account->id}")
            ->assertRedirect();

        $this->assertDatabaseHas('channel_accounts', ['id' => $account->id]);
    }

    public function test_destroy_removes_an_unused_account(): void
    {
        $account = $this->connect();

        $this->actingAs($this->owner)
            ->delete("/w/acme/channels/{$account->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('channel_accounts', ['id' => $account->id]);
    }
}
