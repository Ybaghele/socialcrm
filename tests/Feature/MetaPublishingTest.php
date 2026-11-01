<?php

namespace Tests\Feature;

use App\Exceptions\MetaPublishException;
use App\Models\AdCampaign;
use App\Models\ChannelAccount;
use App\Models\MetaConnection;
use App\Models\Plan;
use App\Models\PublishLog;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Meta\MetaMarketingService;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3: publishing a wizard campaign to the Meta Marketing Graph API.
 *
 * Every Graph call is faked here — these tests assert the payload we send, the
 * ids we persist, the friendly error mapping and the publish log, never that
 * Meta itself accepted anything.
 */
class MetaPublishingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private MetaConnection $connection;

    private AdCampaign $campaign;

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

        $this->connection = Tenancy::run($this->workspace, fn (): MetaConnection => MetaConnection::create([
            'ad_account_id' => '1234567890',
            'access_token' => 'secret-token',
            'status' => 'connected',
            'meta' => ['page_id' => 'page_123'],
        ]));

        $this->campaign = $this->makeCampaign();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeCampaign(array $overrides = []): AdCampaign
    {
        return Tenancy::run($this->workspace, function () use ($overrides): AdCampaign {
            $campaign = AdCampaign::create(array_merge([
                'platform' => 'meta',
                'ad_account_id' => '1234567890',
                'name' => 'Diwali Sale',
                'objective' => 'OUTCOME_SALES',
                'budget_type' => 'daily',
                'budget_amount' => 500,
                'status' => 'draft',
            ], $overrides));

            $adSet = $campaign->adSets()->create([
                'name' => 'Diwali Sale - Broad',
                'audience_json' => [
                    'age_min' => 21,
                    'age_max' => 45,
                    'genders' => [1, 2],
                    'locations' => ['IN'],
                ],
                'placements_json' => [
                    'facebook' => true,
                    'instagram' => true,
                    'messenger' => false,
                    'audience_network' => false,
                ],
                'optimization_goal' => 'conversions',
            ]);

            $adSet->creatives()->create([
                'name' => 'Diwali Creative',
                'format' => 'image',
                'media_url' => 'https://example.com/ad.jpg',
                'primary_text' => 'Big savings',
                'headline' => 'Shop now',
                'description' => 'Limited offer',
                'cta' => 'LEARN_MORE',
                'destination_url' => 'https://example.com/offer',
            ]);

            return $campaign;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publishPayload(array $overrides = []): array
    {
        return array_merge([
            'platform' => 'meta',
            'ad_account_id' => '1234567890',
            'name' => 'Diwali Sale',
            'objective' => 'OUTCOME_SALES',
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
        ], $overrides);
    }

    private function fakeSuccessfulPublish(): void
    {
        Http::fake([
            '*act_1234567890/campaigns' => Http::response(['id' => 'camp_100'], 200),
            '*act_1234567890/adsets' => Http::response(['id' => 'adset_100'], 200),
            '*act_1234567890/adcreatives' => Http::response(['id' => 'creative_100'], 200),
            '*act_1234567890/ads' => Http::response(['id' => 'ad_100'], 200),
        ]);
    }

    public function test_publishing_creates_all_meta_objects_and_marks_the_campaign_paused(): void
    {
        $this->fakeSuccessfulPublish();

        $published = Tenancy::run(
            $this->workspace,
            fn (): AdCampaign => app(MetaMarketingService::class)->publish($this->campaign),
        );

        $this->assertSame('camp_100', $published->platform_campaign_id);
        $this->assertSame('paused', $published->status);

        $adSet = $published->adSets()->first();
        $this->assertNotNull($adSet);
        $this->assertSame('adset_100', $adSet->platform_adset_id);
        $this->assertSame('paused', $adSet->status);

        $creative = $adSet->creatives()->first();
        $this->assertNotNull($creative);
        $this->assertSame('creative_100', $creative->platform_creative_id);
        $this->assertSame('ad_100', $creative->platform_ad_id);
        $this->assertSame('paused', $creative->status);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/act_1234567890/campaigns')) {
                return false;
            }

            $data = $request->data();

            return $data['status'] === 'PAUSED'
                && $data['objective'] === 'OUTCOME_SALES'
                && $data['special_ad_categories'] === '[]'
                && $request->hasHeader('Authorization', 'Bearer secret-token');
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/act_1234567890/adsets')) {
                return false;
            }

            $data = $request->data();
            $targeting = json_decode($data['targeting'], true);

            return $data['billing_event'] === 'IMPRESSIONS'
                && $data['campaign_id'] === 'camp_100'
                && $data['daily_budget'] === 50000
                && $targeting['age_min'] === 21
                && in_array('facebook', $targeting['publisher_platforms'], true);
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/act_1234567890/adcreatives')) {
                return false;
            }

            $spec = json_decode($request->data()['object_story_spec'], true);

            return $spec['page_id'] === 'page_123'
                && $spec['link_data']['link'] === 'https://example.com/offer'
                && $spec['link_data']['call_to_action']['type'] === 'LEARN_MORE';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/act_1234567890/ads')) {
                return false;
            }

            $creative = json_decode($request->data()['creative'], true);

            return $request->data()['adset_id'] === 'adset_100'
                && $creative['creative_id'] === 'creative_100'
                && $request->data()['status'] === 'PAUSED';
        });

        $log = Tenancy::run($this->workspace, fn (): ?PublishLog => PublishLog::query()->latest('id')->first());

        $this->assertNotNull($log);
        $this->assertSame(PublishLog::STATUS_SUCCESS, $log->status);
        $this->assertSame('complete', $log->step);
        $this->assertStringContainsString('campaign_id=camp_100', (string) $log->trace);
    }

    public function test_an_expired_token_is_mapped_to_a_friendly_message_and_logged(): void
    {
        Http::fake([
            '*act_1234567890/campaigns' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired on Friday.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                    'fbtrace_id' => 'trace-1',
                ],
            ], 400),
        ]);

        try {
            Tenancy::run($this->workspace, fn () => app(MetaMarketingService::class)->publish($this->campaign));
            $this->fail('Expected a MetaPublishException.');
        } catch (MetaPublishException $e) {
            $this->assertSame('campaign', $e->step);
            $this->assertSame('190', $e->errorCode);
            $this->assertSame('463', $e->errorSubcode);
            $this->assertStringContainsString('expired', strtolower($e->userTitle));
            $this->assertStringContainsString('trace-1', $e->rawMessage);
            $this->assertNotNull($e->publishLogId);
        }

        $this->campaign->refresh();
        $this->assertSame('draft', $this->campaign->status);
        $this->assertNull($this->campaign->platform_campaign_id);

        $log = Tenancy::run($this->workspace, fn (): ?PublishLog => PublishLog::query()->latest('id')->first());

        $this->assertNotNull($log);
        $this->assertSame(PublishLog::STATUS_FAILED, $log->status);
        $this->assertSame('campaign', $log->step);
        $this->assertSame('190', $log->error_code);
        $this->assertSame('463', $log->error_subcode);
        $this->assertStringContainsString('code=190', (string) $log->trace);
    }

    public function test_rate_limit_errors_are_mapped_to_a_plain_english_message(): void
    {
        Http::fake([
            '*act_1234567890/campaigns' => Http::response([
                'error' => ['message' => 'User request limit reached', 'code' => 4, 'type' => 'OAuthException'],
            ], 400),
        ]);

        $this->expectException(MetaPublishException::class);

        try {
            Tenancy::run($this->workspace, fn () => app(MetaMarketingService::class)->publish($this->campaign));
        } catch (MetaPublishException $e) {
            $this->assertStringContainsString('rate-limit', strtolower($e->userTitle));
            $this->assertStringContainsString('wait', strtolower($e->userMessage));

            throw $e;
        }
    }

    public function test_missing_payment_methods_are_mapped_to_a_billing_message(): void
    {
        Http::fake([
            '*act_1234567890/campaigns' => Http::response([
                'error' => ['message' => 'Ad account has no payment method.', 'code' => 100, 'error_subcode' => 33],
            ], 400),
        ]);

        try {
            Tenancy::run($this->workspace, fn () => app(MetaMarketingService::class)->publish($this->campaign));
            $this->fail('Expected a MetaPublishException.');
        } catch (MetaPublishException $e) {
            $this->assertStringContainsString('payment', strtolower($e->userTitle));
            $this->assertStringContainsString('card', strtolower($e->userMessage));
        }
    }

    public function test_the_publish_endpoint_creates_a_campaign_and_returns_the_platform_ids(): void
    {
        $this->fakeSuccessfulPublish();

        $response = $this->actingAs($this->member)
            ->postJson('/w/acme/ads/campaigns/publish', $this->publishPayload());

        $response->assertOk()->assertJson([
            'ok' => true,
            'status' => 'paused',
            'platform_campaign_id' => 'camp_100',
            'platform_adset_id' => 'adset_100',
            'platform_ad_id' => 'ad_100',
        ]);

        $campaign = Tenancy::run(
            $this->workspace,
            fn (): ?AdCampaign => AdCampaign::query()->where('name', 'Diwali Sale')->latest('id')->first(),
        );

        $this->assertNotNull($campaign);
        $this->assertSame('camp_100', $campaign->platform_campaign_id);
        $this->assertSame('paused', $campaign->status);
        $this->assertSame($campaign->id, $response->json('campaign_id'));
    }

    public function test_the_publish_endpoint_returns_422_and_keeps_the_draft_when_meta_rejects_it(): void
    {
        Http::fake([
            '*act_1234567890/campaigns' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'fbtrace_id' => 'trace-2',
                ],
            ], 400),
        ]);

        $response = $this->actingAs($this->member)
            ->postJson('/w/acme/ads/campaigns/publish', $this->publishPayload());

        $response->assertStatus(422)->assertJson([
            'ok' => false,
            'step' => 'campaign',
            'code' => '190',
        ]);

        $response->assertJsonStructure(['ok', 'title', 'message', 'raw', 'step', 'code', 'log_id', 'campaign_id']);

        $campaign = Tenancy::run(
            $this->workspace,
            fn (): ?AdCampaign => AdCampaign::query()->where('name', 'Diwali Sale')->latest('id')->first(),
        );

        $this->assertNotNull($campaign);
        $this->assertSame('draft', $campaign->status);
        $this->assertNull($campaign->platform_campaign_id);

        $log = Tenancy::run($this->workspace, fn (): ?PublishLog => PublishLog::query()->latest('id')->first());
        $this->assertNotNull($log);
        $this->assertSame(PublishLog::STATUS_FAILED, $log->status);
        $this->assertSame($campaign->id, $log->ad_campaign_id);
    }

    public function test_a_failed_ad_set_cleans_up_the_meta_campaign_that_was_already_created(): void
    {
        Http::fake([
            '*act_1234567890/campaigns' => Http::response(['id' => 'camp_999'], 200),
            '*act_1234567890/adsets' => Http::response([
                'error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1487],
            ], 400),
            '*camp_999' => Http::response(['success' => true], 200),
        ]);

        try {
            Tenancy::run($this->workspace, fn () => app(MetaMarketingService::class)->publish($this->campaign));
            $this->fail('Expected a MetaPublishException.');
        } catch (MetaPublishException $e) {
            $this->assertSame('ad_set', $e->step);
        }

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), 'camp_999'));

        $this->campaign->refresh();
        $this->assertNull($this->campaign->platform_campaign_id);
        $this->assertSame('draft', $this->campaign->status);
    }

    public function test_page_id_falls_back_to_a_connected_messenger_account(): void
    {
        Tenancy::run($this->workspace, function (): void {
            $this->connection->update(['meta' => []]);

            ChannelAccount::create([
                'channel' => 'messenger',
                'name' => 'Acme Page',
                'external_id' => 'page_from_channel',
                'status' => 'connected',
            ]);
        });

        $this->fakeSuccessfulPublish();

        Tenancy::run($this->workspace, fn () => app(MetaMarketingService::class)->publish($this->campaign));

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/act_1234567890/adcreatives')) {
                return false;
            }

            $spec = json_decode($request->data()['object_story_spec'], true);

            return $spec['page_id'] === 'page_from_channel';
        });
    }

    public function test_publishing_without_a_meta_connection_is_refused_and_logged(): void
    {
        Tenancy::run($this->workspace, fn () => $this->connection->delete());

        try {
            Tenancy::run($this->workspace, fn () => app(MetaMarketingService::class)->publish($this->campaign));
            $this->fail('Expected a MetaPublishException.');
        } catch (MetaPublishException $e) {
            $this->assertSame('connection', $e->step);
            $this->assertStringContainsString('connect', strtolower($e->userTitle));
        }

        $log = Tenancy::run($this->workspace, fn (): ?PublishLog => PublishLog::query()->latest('id')->first());

        $this->assertNotNull($log);
        $this->assertSame('connection', $log->step);
        $this->assertSame(PublishLog::STATUS_FAILED, $log->status);
    }

    public function test_a_member_cannot_publish_another_workspaces_campaign(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::create(['name' => 'Globex', 'slug' => 'globex', 'owner_id' => $otherOwner->id]);

        $foreign = Tenancy::run($other, function (): AdCampaign {
            return AdCampaign::create([
                'platform' => 'meta',
                'name' => 'Globex Campaign',
                'status' => 'draft',
            ]);
        });

        $this->actingAs($this->member)
            ->postJson('/w/acme/ads/campaigns/publish', $this->publishPayload(['campaign_id' => $foreign->id]))
            ->assertNotFound();
    }
}
