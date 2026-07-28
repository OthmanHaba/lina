<?php

namespace App\Services;

use App\Enums\WidgetType;
use App\Models\Alert;
use App\Models\DashboardWidget;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class WidgetDataService
{
    public const RANGES = ['15m' => 900, '1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];

    protected const UNAVAILABLE = 'Log storage is unavailable right now.';

    public function __construct(protected LogQlBuilder $builder) {}

    /** @return array<string, mixed> */
    public function fetch(DashboardWidget $widget, string $range): array
    {
        $seconds = self::RANGES[$range];

        return match ($widget->type) {
            WidgetType::Timeseries => $this->timeseries($widget, $seconds),
            WidgetType::Stat => $this->stat($widget),
            WidgetType::LogStream => $this->logStream($widget, $seconds),
            WidgetType::AlertStatus => $this->alertStatus($widget),
            WidgetType::ServerHealth => $this->serverHealth($widget),
        };
    }

    /** @return array<string, mixed> */
    protected function timeseries(DashboardWidget $widget, int $seconds): array
    {
        $step = max(60, intdiv($seconds, 40));
        $config = $widget->config;
        $logql = $config['logql'] ?? null;

        if (is_string($logql) && $logql !== '') {
            $query = $logql;
        } else {
            $selector = $this->selector($widget);

            if ($selector === null) {
                return ['series' => []];
            }

            $by = match ($config['group_by'] ?? null) {
                'server' => ' by (server_id)',
                'source' => ' by (source)',
                default => '',
            };

            $query = "sum{$by}(count_over_time({$selector} [{$step}s]))";
        }

        $now = (int) now()->timestamp;

        $result = $this->lokiGet($widget->team_id, 'query_range', [
            'query' => $query,
            'start' => ($now - $seconds) * 1_000_000_000,
            'end' => $now * 1_000_000_000,
            'step' => (string) $step,
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        $serverNames = Server::where('team_id', $widget->team_id)->pluck('name', 'id');

        $series = [];

        foreach ($result['data'] as $row) {
            $metric = $row['metric'] ?? [];

            $label = match (true) {
                isset($metric['server_id']) => $serverNames[(int) $metric['server_id']] ?? 'Server '.$metric['server_id'],
                isset($metric['source']) => $metric['source'],
                $metric === [] => 'Logs',
                default => implode(', ', $metric),
            };

            $series[] = [
                'label' => $label,
                'points' => array_map(fn (array $value) => [(int) $value[0], (float) $value[1]], $row['values'] ?? []),
            ];
        }

        return ['series' => $series];
    }

    /** @return array<string, mixed> */
    protected function stat(DashboardWidget $widget): array
    {
        $config = $widget->config;
        $window = (int) ($config['window_minutes'] ?? 60);
        $logql = $config['logql'] ?? null;

        if (is_string($logql) && $logql !== '') {
            $query = $logql;
        } else {
            $selector = $this->selector($widget);

            if ($selector === null) {
                return ['value' => 0, 'previous' => 0];
            }

            $query = "sum(count_over_time({$selector} [{$window}m]))";
        }

        $now = (int) now()->timestamp;

        $current = $this->instantValue($widget->team_id, $query, $now);

        if (is_array($current)) {
            return $current;
        }

        $previous = $this->instantValue($widget->team_id, $query, $now - $window * 60);

        if (is_array($previous)) {
            return $previous;
        }

        return ['value' => $current, 'previous' => $previous];
    }

    /** @return array<string, mixed> */
    protected function logStream(DashboardWidget $widget, int $seconds): array
    {
        $selector = $this->selector($widget);

        if ($selector === null) {
            return ['entries' => []];
        }

        $now = (int) now()->timestamp;

        $result = $this->lokiGet($widget->team_id, 'query_range', [
            'query' => $selector,
            'start' => ($now - $seconds) * 1_000_000_000,
            'end' => $now * 1_000_000_000,
            'limit' => (int) ($widget->config['limit'] ?? 25),
            'direction' => 'backward',
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        $entries = [];

        foreach ($result['data'] as $stream) {
            foreach ($stream['values'] ?? [] as $value) {
                $entries[] = [
                    'ts' => $value[0],
                    'time' => Carbon::createFromTimestampMs((int) ((float) $value[0] / 1_000_000))->toISOString(),
                    'line' => $value[1],
                    'labels' => $stream['stream'] ?? [],
                ];
            }
        }

        usort($entries, fn (array $a, array $b) => [strlen($b['ts']), $b['ts']] <=> [strlen($a['ts']), $a['ts']]);

        return ['entries' => array_slice($entries, 0, (int) ($widget->config['limit'] ?? 25))];
    }

    /** @return array<string, mixed> */
    protected function alertStatus(DashboardWidget $widget): array
    {
        return ['alerts' => Alert::where('team_id', $widget->team_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Alert $alert) => [
                'id' => $alert->id,
                'name' => $alert->name,
                'state' => $alert->state->value,
                'enabled' => $alert->enabled,
                'changedAt' => $alert->last_state_change_at?->toISOString(),
            ])->all()];
    }

    /** @return array<string, mixed> */
    protected function serverHealth(DashboardWidget $widget): array
    {
        return ['servers' => Server::where('team_id', $widget->team_id)
            ->orderBy('name')
            ->get()
            ->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'status' => $server->status(),
                'configStatus' => $server->configStatus(),
            ])->all()];
    }

    /**
     * Build the stream selector from the widget's filters, re-intersecting
     * server ids with the team's current servers (deleted servers drop out).
     */
    protected function selector(DashboardWidget $widget): ?string
    {
        $config = $widget->config;
        $teamServerIds = Server::where('team_id', $widget->team_id)->pluck('id')->all();

        $requested = array_map(intval(...), $config['server_ids'] ?? []);
        $serverIds = $requested === [] ? $teamServerIds : array_values(array_intersect($requested, $teamServerIds));

        if ($serverIds === []) {
            return null;
        }

        return $this->builder->build($serverIds, array_values($config['sources'] ?? []), $config['search'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{data: list<array<string, mixed>>}|array{error: string}
     */
    protected function lokiGet(int $teamId, string $endpoint, array $params): array
    {
        try {
            $response = Http::withHeaders(['X-Scope-OrgID' => 'team-'.$teamId])
                ->get(rtrim(config('services.loki.url'), '/').'/loki/api/v1/'.$endpoint, $params);
        } catch (ConnectionException) {
            return ['error' => self::UNAVAILABLE];
        }

        if ($response->failed()) {
            return ['error' => $response->status() === 400 ? 'Query failed — check the widget query.' : self::UNAVAILABLE];
        }

        return ['data' => $response->json('data.result', [])];
    }

    /**
     * @return float|array{error: string}
     */
    protected function instantValue(int $teamId, string $query, int $time): float|array
    {
        $result = $this->lokiGet($teamId, 'query', [
            'query' => $query,
            'time' => $time * 1_000_000_000,
        ]);

        if (isset($result['error'])) {
            return $result;
        }

        return (float) ($result['data'][0]['value'][1] ?? 0);
    }
}
