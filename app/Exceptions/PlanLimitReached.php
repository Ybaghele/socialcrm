<?php

namespace App\Exceptions;

use RuntimeException;

class PlanLimitReached extends RuntimeException
{
    public function __construct(public readonly string $limit, string $message = '')
    {
        parent::__construct($message ?: "Your plan limit for {$limit} has been reached. Upgrade to add more.");
    }
}
