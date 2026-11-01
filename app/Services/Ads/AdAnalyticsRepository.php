<?php

namespace App\Services\Ads;

use App\Models\AdCampaign;
use Carbon\CarbonImmutable;

/**
 * Builds the per-campaign ad performance report.
 *
 * Sandbox campaigns — those without a published Meta campaign id, or those on
 * the shared test ad account — get deterministic sample rows. The same date
 * always produces the same numbers, so switching between the 7, 30 and 90 day
 * windows keeps the overlapping days consistent.
 *
 * Live campaigns return an explicitly empty report until a live insights source
 * is wired in; the UI shows that message instead of pretending zeros are real.
 */
class AdAnalyticsRepository
{
    /**
     * @return array{
     *     source: 'sample'|'live',
     *     is_mock: bool,
     *     currency: string,
     *     window: array{days: int, from: string, to: string, label: string},
     *     series: array<int, array{date: string, label: string, impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null}>,
     *     totals: array{impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null, ctr: float, conversion_rate: float, frequency: float},
     *     message: string|null
     * }
     */
    public function forCampaign(AdCampaign $campaign, int $days): array
    {
        $days = max(1, $days);
        $to = CarbonImmutable::now()->startOfDay();
        $from = $to->subDays($days - 1);

        if (! $this->usesSampleData($campaign)) {
            return $this->emptyReport($from, $to, $days);
        }

        return $this->sampleReport($campaign, $from, $to, $days);
    }

    /**
     * A campaign needs sample data until Meta has actually created it, or when
     * it lives on the shared test ad account used by the staging environment.
     */
    public function usesSampleData(AdCampaign $campaign): bool
    {
        if (blank($campaign->platform_campaign_id)) {
            return true;
        }

        $sampleAccounts = array_map(
            static fn (string $account): string => strtolower(trim($account)),
            (array) config('omniflow.ads_analytics.sample_ad_account_ids', []),
        );

        return in_array(strtolower(trim((string) $campaign->ad_account_id)), $sampleAccounts, true);
    }

    /**
     * @return array{
     *     source: 'sample',
     *     is_mock: true,
     *     currency: string,
     *     window: array{days: int, from: string, to: string, label: string},
     *     series: array<int, array{date: string, label: string, impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null}>,
     *     totals: array{impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null, ctr: float, conversion_rate: float, frequency: float},
     *     message: string
     * }
     */
    private function sampleReport(AdCampaign $campaign, CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        $dailyBudget = $this->dailyBudget($campaign);
        $series = [];

        for ($offset = 0; $offset < $days; $offset++) {
            $series[] = $this->sampleDay($campaign, $from->addDays($offset), $dailyBudget);
        }

        $impressions = (int) array_sum(array_column($series, 'impressions'));
        $dailyReach = (int) array_sum(array_column($series, 'reach'));
        $clicks = (int) array_sum(array_column($series, 'clicks'));
        $spend = round((float) array_sum(array_column($series, 'spend')), 2);
        $conversions = (int) array_sum(array_column($series, 'conversions'));

        // Daily reach is unique within a day but overlaps across days. Apply a
        // stable dedupe factor so the window reach stays below impressions.
        $reach = max(1, (int) round($dailyReach * 0.8));
        $cpl = $conversions > 0 ? round($spend / $conversions, 2) : null;

        return [
            'source' => 'sample',
            'is_mock' => true,
            'currency' => (string) config('omniflow.ads_analytics.currency', 'INR'),
            'window' => $this->window($from, $to, $days),
            'series' => $series,
            'totals' => [
                'impressions' => $impressions,
                'reach' => $reach,
                'clicks' => $clicks,
                'spend' => $spend,
                'conversions' => $conversions,
                'cpl' => $cpl,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
                'conversion_rate' => $clicks > 0 ? round($conversions / $clicks * 100, 2) : 0.0,
                'frequency' => round($impressions / $reach, 2),
            ],
            'message' => 'Sample data generated for sandbox campaigns. No live Meta spend is affected.',
        ];
    }

    /**
     * @return array{
     *     source: 'live',
     *     is_mock: false,
     *     currency: string,
     *     window: array{days: int, from: string, to: string, label: string},
     *     series: array<int, array{date: string, label: string, impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null}>,
     *     totals: array{impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null, ctr: float, conversion_rate: float, frequency: float},
     *     message: string
     * }
     */
    private function emptyReport(CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        return [
            'source' => 'live',
            'is_mock' => false,
            'currency' => (string) config('omniflow.ads_analytics.currency', 'INR'),
            'window' => $this->window($from, $to, $days),
            'series' => [],
            'totals' => [
                'impressions' => 0,
                'reach' => 0,
                'clicks' => 0,
                'spend' => 0.0,
                'conversions' => 0,
                'cpl' => null,
                'ctr' => 0.0,
                'conversion_rate' => 0.0,
                'frequency' => 0.0,
            ],
            'message' => 'Live Meta insights are not connected yet. This report will populate once a live campaign is available.',
        ];
    }

    /**
     * @return array{date: string, label: string, impressions: int, reach: int, clicks: int, spend: float, conversions: int, cpl: float|null}
     */
    private function sampleDay(AdCampaign $campaign, CarbonImmutable $date, float $dailyBudget): array
    {
        $seed = $campaign->workspace_id.':'.$campaign->id.':'.$date->toDateString();
        $weekendLift = in_array((int) $date->dayOfWeek, [0, 6], true) ? 1.12 : 0.97;
        $seasonality = 1 + 0.16 * sin($date->dayOfYear / 8.5) + 0.06 * cos($date->dayOfYear / 21.0);

        $pace = $weekendLift * $seasonality * $this->noise($seed.':spend', 0.9, 1.1);
        $pace = max(0.8, min(1.15, $pace));
        $spend = max(50.0, round($dailyBudget * $pace, 2));

        $cpm = $this->noise($seed.':cpm', 120.0, 260.0);
        $impressions = max(100, (int) round($spend / $cpm * 1000));

        $frequency = $this->noise($seed.':frequency', 1.15, 2.1);
        $reach = min($impressions, max(50, (int) round($impressions / $frequency)));

        $ctr = $this->noise($seed.':ctr', 0.012, 0.045);
        $clicks = min($impressions, max(1, (int) round($impressions * $ctr)));

        $conversionRate = $this->noise($seed.':cvr', 0.035, 0.12);
        $conversions = min($clicks, max(1, (int) round($clicks * $conversionRate)));

        return [
            'date' => $date->toDateString(),
            'label' => $date->format('j M'),
            'impressions' => $impressions,
            'reach' => $reach,
            'clicks' => $clicks,
            'spend' => $spend,
            'conversions' => $conversions,
            'cpl' => $conversions > 0 ? round($spend / $conversions, 2) : null,
        ];
    }

    private function dailyBudget(AdCampaign $campaign): float
    {
        $budget = (float) ($campaign->budget_amount ?? 0);
        $default = (float) config('omniflow.ads_analytics.default_daily_budget', 750.0);

        if ($budget <= 0) {
            return max(1.0, $default);
        }

        return $campaign->budget_type === 'lifetime'
            ? max(1.0, $budget / 30)
            : max(1.0, $budget);
    }

    /**
     * @return array{days: int, from: string, to: string, label: string}
     */
    private function window(CarbonImmutable $from, CarbonImmutable $to, int $days): array
    {
        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'label' => $from->format('j M').' – '.$to->format('j M Y'),
        ];
    }

    /**
     * A stable 0..1 value for a seed, so sample rows never flicker between requests.
     */
    private function noise(string $seed, float $min, float $max): float
    {
        $hash = hash('sha256', $seed, true);
        $value = unpack('N', substr($hash, 0, 4))[1] / 4294967295;

        return $min + ($max - $min) * $value;
    }
}
