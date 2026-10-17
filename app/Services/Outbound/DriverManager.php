<?php

namespace App\Services\Outbound;

use App\Enums\ChannelType;
use App\Services\Outbound\Drivers\MessengerDriver;
use App\Services\Outbound\Drivers\WhatsAppDriver;

class DriverManager
{
    public function for(ChannelType $channel): ChannelDriver
    {
        return match ($channel) {
            ChannelType::WhatsApp => app(WhatsAppDriver::class),
            ChannelType::Instagram, ChannelType::Messenger => app(MessengerDriver::class),
        };
    }
}
