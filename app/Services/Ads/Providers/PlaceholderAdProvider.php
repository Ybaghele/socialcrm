<?php

namespace App\Services\Ads\Providers;

use App\Exceptions\PlatformIntegrationComingSoon;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdSet;
use App\Services\Ads\Contracts\AdProviderInterface;
use Carbon\CarbonImmutable;

/**
 * Shared shell for providers whose API connection is not live yet.
 *
 * Publishing throws the custom coming-soon exception so the wizard can show a
 * friendly card, while billing and analytics return the same shape the Meta
 * adapter returns. That keeps the UI polymorphic without pretending the
 * platform is connected.
 */
abstract class PlaceholderAdProvider implements AdProviderInterface
{
    private const MESSAGE = 'Platform connection coming soon in the next release phase';

    abstract protected function platform(): string;

    public function publishCampaign(AdCampaign $campaign): AdCampaign
    {
        throw $this->comingSoon();
    }

    public function publishAdSet(AdCampaign $campaign, AdSet $adSet): AdSet
    {
        throw $this->comingSoon();
    }

    public function publishCreative(AdCampaign $campaign, AdSet $adSet, AdCreative $creative): AdCreative
    {
        throw $this->comingSoon();
    }

    /**
     * @return array<string, mixed>
     */
    public function checkBillingStatus(AdCampaign $campaign): array
    {
        return [
            'ok' => false,
            'billing_ready' => false,
            'warning' => self::MESSAGE,
            'cta' => null,
            'platform' => $this->platform(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchAnalytics(AdCampaign $campaign, int $days = 30): array
    {
        $days = max(1, $days);
        $to = CarbonImmutable::now()->startOfDay();
        $from = $to->subDays($days - 1);

        return [
            'source' => 'placeholder',
            'is_mock' => false,
            'currency' => (string) config('omniflow.ads_analytics.currency', 'INR'),
            'window' => [
                'days' => $days,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->format('j M').' – '.$to->format('j M Y'),
            ],
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
            'message' => self::MESSAGE,
        ];
    }

    private function comingSoon(): PlatformIntegrationComingSoon
    {
        return new PlatformIntegrationComingSoon($this->platform(), self::MESSAGE);
    }
}
