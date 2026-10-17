<?php

namespace App\Services\Inbound;

use App\Enums\ChannelType;
use Carbon\CarbonImmutable;

/** A provider-agnostic inbound message, produced by MetaPayloadParser. */
final readonly class InboundMessage
{
    public function __construct(
        public ChannelType $channel,
        public string $accountExternalId,   // phone_number_id / IG account id / page id
        public string $senderExternalId,    // wa_id / IGSID / PSID
        public ?string $senderName,
        public ?string $senderPhone,        // E.164 with '+', WhatsApp only
        public string $externalMessageId,   // wamid.* / mid.*
        public string $type,
        public ?string $body,
        public array $payload,
        public CarbonImmutable $sentAt,
    ) {
    }
}
