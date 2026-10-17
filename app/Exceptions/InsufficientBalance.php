<?php

namespace App\Exceptions;

class InsufficientBalance extends OutboundNotAllowed
{
    public function __construct(string $message = 'Wallet balance is too low to send this message. Top up your wallet.')
    {
        parent::__construct('insufficient_balance', $message);
    }
}
