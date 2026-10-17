<?php

namespace App\Services\Outbound;

use App\Models\ChannelAccount;
use App\Models\ContactChannelIdentity;
use App\Models\Message;

interface ChannelDriver
{
    /** @throws SendFailed */
    public function send(Message $message, ChannelAccount $account, ContactChannelIdentity $identity): SendResult;
}
