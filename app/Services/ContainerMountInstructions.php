<?php

namespace App\Services;

use App\Models\LogSource;

class ContainerMountInstructions
{
    /**
     * The copy-paste bind-mount runbook for a container source. The host path
     * is derived from the source id so it is always stable and unique.
     *
     * @return array{hostPath: string, containerPath: string, compose: string, dockerRun: string, prepare: string, recreate: string, tailGlob: string}
     */
    public static function for(LogSource $source): array
    {
        $hostPath = '/var/log/lina/'.$source->id;
        $containerPath = (string) ($source->config['in_container_path'] ?? '');

        return [
            'hostPath' => $hostPath,
            'containerPath' => $containerPath,
            'compose' => "- {$hostPath}:{$containerPath}",
            'dockerRun' => "-v {$hostPath}:{$containerPath}",
            'prepare' => "sudo mkdir -p {$hostPath} && sudo chmod 777 {$hostPath}",
            'recreate' => 'docker compose up -d',
            'tailGlob' => "{$hostPath}/*.log",
        ];
    }
}
