<?php

namespace App\Enums;

enum AlertState: string
{
    case Ok = 'ok';
    case Firing = 'firing';
}
