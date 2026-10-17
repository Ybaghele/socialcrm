<?php

namespace App\Services\Inbound;

use App\Enums\ChannelType;
use App\Enums\MessageStatus;
use Carbon\CarbonImmutable;

final readonly class StatusUpdate
{
    public function __construct(
        public ChannelType $channel,
        public string $accountExternalId,
        public string $externalMessageId,
        public MessageStatus $status,
        public CarbonImmutable $at,
        public ?array $error = null,
    ) {
    }
}
