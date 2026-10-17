<?php

namespace App\Services\Outbound;

use RuntimeException;

/** A provider send failed. `retryable` = worth trying again (timeouts, rate limits, 5xx). */
class SendFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
