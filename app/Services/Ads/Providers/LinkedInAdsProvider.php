<?php

namespace App\Services\Ads\Providers;

/**
 * LinkedIn Ads adapter shell.
 *
 * The API connection is intentionally not wired yet: publishing throws
 * PlatformIntegrationComingSoon and analytics returns an empty placeholder
 * report. Fill in the platform-specific calls when LinkedIn access is approved.
 */
class LinkedInAdsProvider extends PlaceholderAdProvider
{
    protected function platform(): string
    {
        return 'linkedin';
    }
}
