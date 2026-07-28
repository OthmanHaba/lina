<?php

namespace App\Enums;

enum WidgetType: string
{
    case Timeseries = 'timeseries';
    case Stat = 'stat';
    case LogStream = 'log_stream';
    case AlertStatus = 'alert_status';
    case ServerHealth = 'server_health';
}
