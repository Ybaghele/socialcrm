<?php

namespace App\Exceptions;

use RuntimeException;

/** A reply was refused *before* anything was sent or charged. `reason` is a stable code for the UI. */
class OutboundNotAllowed extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
