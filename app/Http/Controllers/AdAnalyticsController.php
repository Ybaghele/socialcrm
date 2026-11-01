<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Services\Ads\AdAnalyticsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-campaign ad reporting. The repository decides whether the campaign is a
 * sandbox campaign and therefore needs deterministic sample rows.
 */
class AdAnalyticsController extends Controller
{
    public function __construct(private readonly AdAnalyticsRepository $analytics) {}

    public function show(Request $request, string $workspace, AdCampaign $campaign): Response
    {
        $ranges = array_values((array) config('omniflow.ads_analytics.ranges', [7, 30, 90]));
        $default = (int) config('omniflow.ads_analytics.default_range', 30);
        $requested = (int) $request->integer('range', $default);
        $days = in_array($requested, $ranges, true) ? $requested : $default;

        return Inertia::render('Ads/CampaignAnalytics', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'platform' => $campaign->platform,
                'objective' => $campaign->objective,
                'ad_account_id' => $campaign->ad_account_id,
                'platform_campaign_id' => $campaign->platform_campaign_id,
                'budget_type' => $campaign->budget_type,
                'budget_amount' => $campaign->budget_amount,
            ],
            'range' => $days,
            'ranges' => $ranges,
            'report' => $this->analytics->forCampaign($campaign, $days),
        ]);
    }
}
