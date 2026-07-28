<?php

namespace App\Console\Commands;

use App\Services\LokiOverridesWriter;
use Illuminate\Console\Command;

class WriteLokiOverrides extends Command
{
    protected $signature = 'loki:write-overrides';

    protected $description = 'Render and write the Loki per-tenant retention overrides file';

    public function handle(LokiOverridesWriter $writer): int
    {
        $writer->write();

        $path = config('services.loki.overrides_path');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            $this->error('Overrides file was not written — check LOKI_OVERRIDES_PATH and directory permissions (details in the log).');

            return self::FAILURE;
        }

        $this->info('Overrides written to '.$path);

        return self::SUCCESS;
    }
}
