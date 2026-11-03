<?php

namespace Tests\Feature;

use App\Exceptions\PlatformIntegrationComingSoon;
use App\Models\AdCampaign;
use App\Models\MetaConnection;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ads\AdProviderFactory;
use App\Services\Ads\Providers\GoogleAdsProvider;
use App\Services\Ads\Providers\LinkedInAdsProvider;
use App\Services\Ads\Providers\MetaProvider;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 5: the provider factory resolves the right adapter for each platform,
 * and the Google/LinkedIn shells stay honest about their coming-soon state.
 */
class AdProviderTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();

        $this->workspace = Workspace::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'owner_id' => $this->member->id,
        ]);
        $this->workspace->users()->attach($this->member->id, ['role' => 'owner']);

        $plan = Plan::create([
            'name' => 'Growth',
            'slug' => 'growth',
            'price_paise' => 499900,
            'monthly_active_contacts' => 2500,
            'max_instagram_accounts' => 5,
            'max_agent_seats' => 5,
        ]);

        Subscription::create([
            'workspace_id' => $this->workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCampaign(string $platform, array $overrides = []): AdCampaign
    {
        return Tenancy::run($this->workspace, fn (): AdCampaign => AdCampaign::create(array_merge([
            'platform' => $platform,
            'ad_account_id' => 'act_230817132',
            'name' => ucfirst($platform).' Campaign',
            'objective' => 'OUTCOME_LEADS',
            'budget_type' => 'daily',
            'budget_amount' => 500,
            'status' => 'draft',
        ], $overrides)));
    }

    /**
     * @return array<string, mixed>
     */
    private function publishPayload(string $platform): array
    {
        return [
            'platform' => $platform,
            'ad_account_id' => 'act_230817132',
            'name' => ucfirst($platform).' Campaign',
            'objective' => 'OUTCOME_LEADS',
            'special_category' => '',
            'budget_type' => 'daily',
            'budget_amount' => 500,
            'status' => 'draft',
            'audience_json' => ['age_min' => 21, 'age_max' => 45, 'genders' => [1, 2], 'locations' => ['IN']],
            'placements_json' => ['facebook' => true, 'instagram' => true, 'messenger' => false, 'audience_network' => false],
            'optimization_goal' => 'conversions',
            'start_at' => null,
            'end_at' => null,
            'format' => 'image',
            'media_url' => 'https://example.com/ad.jpg',
            'primary_text' => 'Big savings',
            'headline' => 'Shop now',
            'description' => 'Limited offer',
            'cta' => 'LEARN_MORE',
            'destination_url' => 'https://example.com/offer',
        ];
    }

    public function test_the_factory_resolves_each_supported_platform(): void
    {
        $factory = app(AdProviderFactory::class);

        $this->assertInstanceOf(MetaProvider::class, $factory->forPlatform('meta'));
        $this->assertInstanceOf(GoogleAdsProvider::class, $factory->forPlatform('google'));
        $this->assertInstanceOf(LinkedInAdsProvider::class, $factory->forPlatform('linkedin'));

        $this->assertInstanceOf(MetaProvider::class, $factory->forCampaign($this->makeCampaign('meta')));
        $this->assertInstanceOf(GoogleAdsProvider::class, $factory->forCampaign($this->makeCampaign('google')));
        $this->assertInstanceOf(LinkedInAdsProvider::class, $factory->forCampaign($this->makeCampaign('linkedin')));
    }

    public function test_the_factory_rejects_an_unknown_platform(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AdProviderFactory::class)->forPlatform('tiktok');
    }

    public function test_placeholder_providers_throw_the_coming_soon_exception_when_publishing(): void
    {
        foreach (['google', 'linkedin'] as $platform) {
            $campaign = $this->makeCampaign($platform);
            $provider = app(AdProviderFactory::class)->forPlatform($platform);

            try {
                $provider->publishCampaign($campaign);
                $this->fail("Expected a coming-soon exception for {$platform}.");
            } catch (PlatformIntegrationComingSoon $e) {
                $this->assertSame($platform, $e->platform);
                $this->assertSame('Platform connection coming soon in the next release phase', $e->getMessage());
            }
        }
    }

    public function test_placeholder_providers_return_a_coming_soon_billing_payload(): void
    {
        $campaign = $this->makeCampaign('google');
        $payload = app(AdProviderFactory::class)->forPlatform('google')->checkBillingStatus($campaign);

        $this->assertFalse($payload['billing_ready']);
        $this->assertSame('Platform connection coming soon in the next release phase', $payload['warning']);
        $this->assertSame('google', $payload['platform']);
    }

    public function test_placeholder_providers_return_an_empty_placeholder_report(): void
    {
        $campaign = $this->makeCampaign('linkedin');
        $report = app(AdProviderFactory::class)->forPlatform('linkedin')->fetchAnalytics($campaign, 7);

        $this->assertSame('placeholder', $report['source']);
        $this->assertFalse($report['is_mock']);
        $this->assertSame(7, $report['window']['days']);
        $this->assertSame([], $report['series']);
        $this->assertSame(0, $report['totals']['impressions']);
        $this->assertSame('Platform connection coming soon in the next release phase', $report['message']);
    }

    public function test_the_publish_endpoint_keeps_a_google_draft_and_returns_the_coming_soon_message(): void
    {
        $response = $this->actingAs($this->member)
            ->postJson('/w/acme/ads/campaigns/publish', $this->publishPayload('google'));

        $response->assertStatus(422)->assertJson([
            'ok' => false,
            'step' => 'platform',
            'title' => 'Google Ads is coming soon',
            'message' => 'Platform connection coming soon in the next release phase',
        ]);

        $campaign = Tenancy::run(
            $this->workspace,
            fn (): ?AdCampaign => AdCampaign::query()->where('name', 'Google Campaign')->latest('id')->first(),
        );

        $this->assertNotNull($campaign);
        $this->assertSame('draft', $campaign->status);
        $this->assertNull($campaign->platform_campaign_id);
    }

    public function test_the_analytics_endpoint_uses_the_placeholder_provider_for_google(): void
    {
        $campaign = $this->makeCampaign('google');

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics?range=7")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ads/CampaignAnalytics')
                ->where('campaign.platform', 'google')
                ->where('range', 7)
                ->where('report.source', 'placeholder')
                ->where('report.is_mock', false)
                ->has('report.series', 0)
                ->where('report.totals.impressions', 0)
                ->where('report.message', 'Platform connection coming soon in the next release phase')
            );
    }

    public function test_the_billing_endpoint_uses_the_placeholder_provider_for_google(): void
    {
        $this->actingAs($this->member)
            ->postJson('/w/acme/ads/billing/check', [
                'platform' => 'google',
                'ad_account_id' => 'act_230817132',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => false,
                'billing_ready' => false,
                'warning' => 'Platform connection coming soon in the next release phase',
                'platform' => 'google',
            ]);
    }

    public function test_the_billing_endpoint_keeps_the_meta_funding_source_check(): void
    {
        Tenancy::run($this->workspace, fn (): MetaConnection => MetaConnection::create([
            'ad_account_id' => '1234567890',
            'access_token' => 'secret-token',
            'status' => 'connected',
            'meta' => ['page_id' => 'page_123'],
        ]));

        Http::fake([
            '*act_1234567890*' => Http::response([
                'id' => 'act_1234567890',
                'account_status' => 1,
                'funding_source_details' => ['id' => 'card_1', 'type' => 1],
            ], 200),
        ]);

        $this->actingAs($this->member)
            ->postJson('/w/acme/ads/billing/check', [
                'platform' => 'meta',
                'ad_account_id' => '1234567890',
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'billing_ready' => true,
            ]);
    }
}
