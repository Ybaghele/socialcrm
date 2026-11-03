<?php

namespace App\Services\Ads;

use App\Models\AdCampaign;
use App\Services\Ads\Contracts\AdProviderInterface;
use App\Services\Ads\Providers\GoogleAdsProvider;
use App\Services\Ads\Providers\LinkedInAdsProvider;
use App\Services\Ads\Providers\MetaProvider;
use InvalidArgumentException;

/**
 * Resolves the concrete ad adapter for a campaign's platform.
 *
 * Controllers depend on this factory instead of a provider class so adding a
 * platform only means adding one match arm and one adapter.
 */
class AdProviderFactory
{
    public function __construct(
        private readonly MetaProvider $meta,
        private readonly GoogleAdsProvider $google,
        private readonly LinkedInAdsProvider $linkedin,
    ) {}

    public function forCampaign(AdCampaign $campaign): AdProviderInterface
    {
        return $this->forPlatform((string) $campaign->platform);
    }

    public function forPlatform(string $platform): AdProviderInterface
    {
        return match (strtolower(trim($platform))) {
            'meta' => $this->meta,
            'google' => $this->google,
            'linkedin' => $this->linkedin,
            default => throw new InvalidArgumentException("Unsupported ad platform [{$platform}]."),
        };
    }
}
