<?php

namespace App\Services;

use App\Models\LogSource;

class ContainerMountInstructions
{
    /**
     * The copy-paste bind-mount guidance for a container source. The host path
     * is derived from the source id so it is always stable and unique.
     *
     * @return array{hostPath: string, containerPath: string, compose: string, dockerRun: string, dokploy: string}
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
            'dokploy' => "Add a Bind mount — host path {$hostPath}, container path {$containerPath} — then redeploy the service so the container is recreated with the mount.",
        ];
    }
}
