<?php

namespace App\Services\Ads\Contracts;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdSet;

/**
 * The contract every ad platform adapter implements.
 *
 * The publishing pipeline and analytics controller resolve a concrete adapter
 * through AdProviderFactory, so adding a platform never means branching inside
 * a controller again.
 */
interface AdProviderInterface
{
    /**
     * Publish the campaign shell to the provider.
     */
    public function publishCampaign(AdCampaign $campaign): AdCampaign;

    /**
     * Publish one ad set under a campaign that already exists at the provider.
     */
    public function publishAdSet(AdCampaign $campaign, AdSet $adSet): AdSet;

    /**
     * Publish one creative/ad under an ad set that already exists at the provider.
     */
    public function publishCreative(AdCampaign $campaign, AdSet $adSet, AdCreative $creative): AdCreative;

    /**
     * Check whether the provider can bill this campaign's ad account.
     *
     * @return array<string, mixed>
     */
    public function checkBillingStatus(AdCampaign $campaign): array;

    /**
     * Build the analytics report for a campaign.
     *
     * @return array<string, mixed>
     */
    public function fetchAnalytics(AdCampaign $campaign, int $days = 30): array;
}
