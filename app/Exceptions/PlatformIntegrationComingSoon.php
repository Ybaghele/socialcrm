<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by provider shells whose platform connection is not live yet.
 *
 * The array shape matches MetaPublishException::toArray() so the wizard can
 * render a friendly card without knowing which provider raised the error.
 */
class PlatformIntegrationComingSoon extends RuntimeException
{
    public function __construct(
        public readonly string $platform,
        string $message = 'Platform connection coming soon in the next release phase',
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => false,
            'step' => 'platform',
            'title' => $this->label().' is coming soon',
            'message' => $this->getMessage(),
            'raw' => $this->getMessage(),
            'code' => null,
            'subcode' => null,
            'type' => 'platform_coming_soon',
            'log_id' => null,
        ];
    }

    private function label(): string
    {
        return match ($this->platform) {
            'google' => 'Google Ads',
            'linkedin' => 'LinkedIn Ads',
            default => ucfirst($this->platform).' Ads',
        };
    }
}
