<?php

namespace App\Enums;

enum LogSourceType: string
{
    case Systemd = 'systemd';
    case Docker = 'docker';
    case Nginx = 'nginx';
    case File = 'file';
}
