<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ads\AdAnalyticsRepository;
use App\Support\Tenancy\Tenancy;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Per-campaign ad reporting. Sandbox campaigns must produce stable sample rows,
 * windows must overlap consistently, and another workspace's campaign must 404.
 */
class AdAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $now = Carbon::parse('2026-11-01 12:00:00');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeCampaign(array $attributes = []): AdCampaign
    {
        return Tenancy::run($this->workspace, fn (): AdCampaign => AdCampaign::create(array_merge([
            'platform' => 'meta',
            'name' => 'Diwali Sale',
            'objective' => 'OUTCOME_LEADS',
            'budget_type' => 'daily',
            'budget_amount' => 500,
            'status' => 'draft',
            'ad_account_id' => 'act_230817132',
            'platform_campaign_id' => null,
        ], $attributes)));
    }

    /**
     * @return array<string, mixed>
     */
    private function analyticsProps(AdCampaign $campaign, int $range = 30): array
    {
        $response = $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics?range={$range}")
            ->assertOk();

        $page = $response->viewData('page');

        if (is_string($page)) {
            $page = json_decode($page, true);
        }

        return is_array($page) ? ($page['props'] ?? []) : [];
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $campaign = $this->makeCampaign();

        $this->get("/w/acme/ads/campaigns/{$campaign->id}/analytics")
            ->assertRedirect('/login');
    }

    public function test_a_campaign_without_a_platform_id_gets_a_sample_report(): void
    {
        $campaign = $this->makeCampaign();

        $response = $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics");

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Ads/CampaignAnalytics')
            ->where('campaign.id', $campaign->id)
            ->where('range', 30)
            ->where('report.is_mock', true)
            ->where('report.source', 'sample')
            ->has('report.series', 30)
            ->where('report.series.29.date', '2026-11-01')
            ->where('report.window.from', '2026-10-03')
            ->where('report.window.to', '2026-11-01')
        );

        $report = $this->analyticsProps($campaign)['report'];

        $this->assertGreaterThan(0, $report['totals']['impressions']);
        $this->assertGreaterThan(0, $report['totals']['reach']);
        $this->assertGreaterThan(0, $report['totals']['clicks']);
        $this->assertGreaterThan(0, $report['totals']['spend']);
        $this->assertGreaterThan(0, $report['totals']['conversions']);
        $this->assertGreaterThan(0, $report['totals']['cpl']);
        $this->assertLessThan($report['totals']['impressions'], $report['totals']['reach']);
        $this->assertLessThan($report['totals']['impressions'], $report['totals']['clicks']);
        $this->assertLessThanOrEqual($report['totals']['clicks'], $report['totals']['conversions']);
        $this->assertEqualsWithDelta(
            round($report['totals']['spend'] / $report['totals']['conversions'], 2),
            $report['totals']['cpl'],
            0.01,
        );

        $this->assertSame((int) array_sum(array_column($report['series'], 'impressions')), $report['totals']['impressions']);
        $this->assertSame((int) array_sum(array_column($report['series'], 'clicks')), $report['totals']['clicks']);
        $this->assertSame((int) array_sum(array_column($report['series'], 'conversions')), $report['totals']['conversions']);
        $this->assertEqualsWithDelta(
            (float) array_sum(array_column($report['series'], 'spend')),
            $report['totals']['spend'],
            0.01,
        );
    }

    public function test_a_campaign_on_the_test_ad_account_uses_sample_data_even_with_a_platform_id(): void
    {
        $campaign = $this->makeCampaign([
            'ad_account_id' => 'act_230817132',
            'platform_campaign_id' => '120210000000000001',
        ]);

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.is_mock', true)
                ->where('report.source', 'sample')
                ->has('report.series', 30)
            );
    }

    public function test_the_window_can_be_switched_between_7_30_and_90_days(): void
    {
        $campaign = $this->makeCampaign();

        foreach ([7, 30, 90] as $days) {
            $this->actingAs($this->member)
                ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics?range={$days}")
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('range', $days)
                    ->where('report.window.days', $days)
                    ->has('report.series', $days)
                );
        }
    }

    public function test_an_unknown_window_falls_back_to_the_default(): void
    {
        $campaign = $this->makeCampaign();

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics?range=1000")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('range', 30)
                ->has('report.series', 30)
            );
    }

    public function test_overlapping_windows_reuse_the_same_daily_rows(): void
    {
        $campaign = $this->makeCampaign();
        $repository = app(AdAnalyticsRepository::class);

        $sevenDays = $repository->forCampaign($campaign, 7);
        $thirtyDays = $repository->forCampaign($campaign, 30);

        $this->assertSame(array_slice($thirtyDays['series'], -7), $sevenDays['series']);
    }

    public function test_a_member_cannot_view_analytics_for_another_workspaces_campaign(): void
    {
        $otherOwner = User::factory()->create();
        $other = Workspace::create([
            'name' => 'Globex',
            'slug' => 'globex',
            'owner_id' => $otherOwner->id,
        ]);

        $foreign = Tenancy::run($other, fn (): AdCampaign => AdCampaign::create([
            'platform' => 'meta',
            'name' => 'Globex Campaign',
            'status' => 'draft',
        ]));

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$foreign->id}/analytics")
            ->assertNotFound();
    }

    public function test_a_live_campaign_outside_the_test_account_returns_an_empty_live_report(): void
    {
        $campaign = $this->makeCampaign([
            'ad_account_id' => 'act_1048689921564742',
            'platform_campaign_id' => '120210000000000002',
        ]);

        $this->actingAs($this->member)
            ->get("/w/acme/ads/campaigns/{$campaign->id}/analytics")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.is_mock', false)
                ->where('report.source', 'live')
                ->has('report.series', 0)
                ->where('report.totals.impressions', 0)
                ->where('report.totals.cpl', null)
            );
    }
}
