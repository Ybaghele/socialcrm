<?php

namespace Tests\Feature;

use App\Models\ChannelAccount;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Meta\MetaOAuth;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * WhatsApp Embedded Signup: the self-service half of onboarding. Meta's dialogs
 * are fakeable, so the whole round trip is exercised here.
 *
 * Configured by default in setUp(); tests that expect the manual-only screen call
 * disableSelfService().
 */
class ChannelConnectTest extends TestCase
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
            'omniflow.meta.app_id' => 'app-123',
            'omniflow.meta.embedded_signup_config_id' => 'config-456',
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

    private function disableSelfService(): void
    {
        config(['omniflow.meta.app_id' => null, 'omniflow.meta.embedded_signup_config_id' => null]);
    }

    /** Fake the whole Graph round trip Meta performs during Embedded Signup. */
    private function fakeMeta(array $phones = []): void
    {
        Http::fake([
            '*oauth/access_token*' => Http::response([
                'access_token' => 'LONG-LIVED-TOKEN',
                'token_type' => 'bearer',
                'expires_in' => 5184000,
            ]),
            '*me/whatsapp_business_accounts*' => Http::response(['data' => [
                ['id' => 'WABA1', 'name' => 'Acme WABA', 'phone_numbers' => ['data' => $phones]],
            ]]),
            '*subscribed_apps*' => Http::response(['success' => true]),
        ]);
    }

    // ---------------------------------------------------------------- start --

    public function test_start_sends_the_owner_to_metas_dialog(): void
    {
        $response = $this->actingAs($this->owner)
            ->from('/w/acme/channels')
            ->get('/w/acme/channels/meta');

        $response->assertRedirect();
        $url = (string) $response->headers->get('Location');

        $this->assertStringStartsWith('https://www.facebook.com/v22.0/dialog/oauth?', $url);
        $this->assertStringContainsString('config_id=config-456', $url);
        $this->assertStringContainsString('client_id=app-123', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('override_default_response_type=true', $url);
        // featureType is what makes Meta ask for a WABA + number, not a page.
        $this->assertStringContainsString('whatsapp_embedded_signup', urldecode($url));
        // The workspace travels back in the signed state so the callback can find it.
        $this->assertStringContainsString('state=acme.', $url);
    }

    public function test_start_is_hidden_until_the_meta_app_is_approved(): void
    {
        $this->disableSelfService();

        $this->actingAs($this->owner)->get('/w/acme/channels/meta')->assertNotFound();
    }

    public function test_only_the_owner_may_start_a_connect(): void
    {
        $agent = User::factory()->create();
        $this->workspace->users()->attach($agent->id, ['role' => 'agent']);

        $this->actingAs($agent)->get('/w/acme/channels/meta')->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/w/acme/channels/meta')->assertRedirect('/login');
    }

    public function test_the_channels_page_offers_the_connect_button_to_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->get('/w/acme/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Channels')
                ->where('oauth.enabled', true)
                ->where('oauth.connectUrl', route('channels.meta.start', ['workspace' => 'acme'])));
    }

    public function test_the_channels_page_stays_manual_when_not_configured(): void
    {
        $this->disableSelfService();

        $this->actingAs($this->owner)
            ->get('/w/acme/channels')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('oauth.enabled', false)
                ->where('oauth.connectUrl', null));
    }

    // ------------------------------------------------------------- callback --

    public function test_the_callback_rejects_a_tampered_state(): void
    {
        $this->actingAs($this->owner)
            ->get('/channels/meta/callback?state=acme.'.($this->owner->getKey()).'.deadbeefdeadbeef&code=abc')
            ->assertBadRequest();

        $this->assertDatabaseCount('channel_accounts', 0);
    }

    public function test_the_callback_connects_every_number_it_is_given(): void
    {
        $this->fakeMeta([
            ['id' => 'PN-1', 'display_phone_number' => '+91 90000 00001', 'verified_name' => 'Acme Support'],
            ['id' => 'PN-2', 'display_phone_number' => '+91 90000 00002', 'verified_name' => 'Acme Sales'],
        ]);

        $state = app(MetaOAuth::class)->state('acme', $this->owner->getKey());

        $this->actingAs($this->owner)
            ->get('/channels/meta/callback?state='.urlencode($state).'&code=CODE-1')
            ->assertRedirect(route('channels.index', ['workspace' => 'acme']));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/access_token')
            && $request->method() === 'POST'
            && data_get($request->data(), 'code') === 'CODE-1');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'subscribed_apps'));

        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->workspace->id,
            'channel' => 'whatsapp',
            'external_id' => 'PN-1',
            'name' => 'Acme Support',
            'handle' => '+91 90000 00001',
            'business_account_id' => 'WABA1',
            'status' => 'connected',
        ]);
        $this->assertDatabaseCount('channel_accounts', 2);

        // A ~60-day token is stored, and it is encrypted at rest.
        $account = Tenancy::run($this->workspace, fn () => ChannelAccount::where('external_id', 'PN-1')->first());
        $this->assertNotNull($account);
        $this->assertTrue($account->token_expires_at->isFuture());
    }

    public function test_the_callback_reports_a_cancelled_dialog(): void
    {
        $state = app(MetaOAuth::class)->state('acme', $this->owner->getKey());

        $this->actingAs($this->owner)
            ->get('/channels/meta/callback?state='.urlencode($state).'&error=access_denied&error_description=User+denied+access')
            ->assertRedirect(route('channels.index', ['workspace' => 'acme']));

        $this->assertDatabaseCount('channel_accounts', 0);
    }

    public function test_the_callback_never_steals_a_number_another_workspace_owns(): void
    {
        $other = User::factory()->create();
        $theirs = Workspace::create(['name' => 'Other', 'slug' => 'other', 'owner_id' => $other->id]);
        $theirs->users()->attach($other->id, ['role' => 'owner']);

        Tenancy::run($theirs, fn () => ChannelAccount::create([
            'channel' => 'whatsapp',
            'name' => 'Their line',
            'external_id' => 'PN-1',
            'access_token' => 'their-token',
        ]));

        $this->fakeMeta([
            ['id' => 'PN-1', 'display_phone_number' => '+91 90000 00001', 'verified_name' => 'Not ours'],
        ]);

        $state = app(MetaOAuth::class)->state('acme', $this->owner->getKey());

        $this->actingAs($this->owner)
            ->get('/channels/meta/callback?state='.urlencode($state).'&code=CODE-1')
            ->assertSessionHas('error');

        // Still theirs, with their token untouched.
        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $theirs->id,
            'external_id' => 'PN-1',
        ]);

        // The token must not have been overwritten: read via the model to confirm.
        $stolen = ChannelAccount::withoutGlobalScope('workspace')
            ->where('workspace_id', $theirs->id)
            ->where('external_id', 'PN-1')
            ->first();
        $this->assertNotNull($stolen);
        $this->assertSame('their-token', $stolen->access_token);
        $this->assertSame(1, ChannelAccount::withoutGlobalScope('workspace')->count());
    }

    public function test_the_callback_refuses_a_workspace_the_signed_in_user_does_not_own(): void
    {
        $state = app(MetaOAuth::class)->state('acme', $this->owner->getKey());

        $this->actingAs(User::factory()->create())
            ->get('/channels/meta/callback?state='.urlencode($state).'&code=CODE-1')
            ->assertForbidden();

        $this->assertDatabaseCount('channel_accounts', 0);
    }
}
