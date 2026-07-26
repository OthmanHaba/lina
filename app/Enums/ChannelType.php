<?php

namespace App\Enums;

enum ChannelType: string
{
    case Email = 'email';
    case Webhook = 'webhook';
    case Telegram = 'telegram';
}
