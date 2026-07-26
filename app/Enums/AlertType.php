<?php

namespace App\Enums;

enum AlertType: string
{
    case Threshold = 'threshold';
    case MissingLogs = 'missing_logs';
}
