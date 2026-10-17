<?php

namespace App\Services\Outbound;

final readonly class SendResult
{
    public function __construct(public string $externalId)
    {
    }
}
