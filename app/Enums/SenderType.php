<?php

namespace App\Enums;

enum SenderType: string
{
    case Contact = 'contact';
    case User = 'user';
    case Bot = 'bot';
    case System = 'system';
}
