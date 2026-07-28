<?php

namespace App\Services;

use App\Models\Team;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class LokiOverridesWriter
{
    /**
     * Atomically write the Loki per-tenant retention overrides file.
     * Never throws: the DB is the source of truth and `loki:write-overrides`
     * can repair a failed write; Loki keeps using the previous file meanwhile.
     */
    public function write(): void
    {
        try {
            $path = config('services.loki.overrides_path');
            File::ensureDirectoryExists(dirname($path));

            $temp = $path.'.tmp';
            file_put_contents($temp, $this->render());
            rename($temp, $path);
        } catch (Throwable $exception) {
            Log::error('Failed to write Loki overrides', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * The complete overrides YAML. Hand-rendered: the structure is tiny and
     * every interpolated value is an app-controlled integer.
     */
    public function render(): string
    {
        $teams = Team::orderBy('id')
            ->with(['servers' => fn ($query) => $query->whereNotNull('retention_days')->orderBy('id')])
            ->get();

        $lines = ['overrides:'];

        foreach ($teams as $team) {
            $lines[] = "  team-{$team->id}:";
            $lines[] = "    retention_period: {$team->retention_days}d";

            if ($team->servers->isNotEmpty()) {
                $lines[] = '    retention_stream:';

                foreach ($team->servers as $server) {
                    $lines[] = "      - selector: '{server_id=\"{$server->id}\"}'";
                    $lines[] = '        priority: 1';
                    $lines[] = "        period: {$server->retention_days}d";
                }
            }
        }

        return implode("\n", $lines)."\n";
    }
}
