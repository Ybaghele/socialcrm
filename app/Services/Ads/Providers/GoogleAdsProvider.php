<?php

namespace App\Services\Ads\Providers;

/**
 * Google Ads adapter shell.
 *
 * The API connection is intentionally not wired yet: publishing throws
 * PlatformIntegrationComingSoon and analytics returns an empty placeholder
 * report. Fill in the platform-specific calls when Google access is approved.
 */
class GoogleAdsProvider extends PlaceholderAdProvider
{
    protected function platform(): string
    {
        return 'google';
    }
}
