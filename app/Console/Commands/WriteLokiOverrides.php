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

        $this->info('Overrides written to '.config('services.loki.overrides_path'));

        return self::SUCCESS;
    }
}
