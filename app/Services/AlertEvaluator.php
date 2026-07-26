<?php

namespace App\Services;

use App\Enums\AlertState;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlertEvaluator
{
    public function __construct(
        private LogQlBuilder $builder,
        private AlertNotifier $notifier,
    ) {}

    /**
     * Evaluate one alert: count matching lines over the window and apply
     * state-transition rules. Errors skip the round; they never throw.
     */
    public function evaluate(Alert $alert): void
    {
        $teamServerIds = Server::where('team_id', $alert->team_id)->pluck('id')->all();
        $configured = $alert->config['servers'] ?? [];
        $serverIds = $configured === []
            ? $teamServerIds
            : array_values(array_intersect(array_map(intval(...), $configured), $teamServerIds));

        if ($serverIds === []) {
            $alert->forceFill(['last_evaluated_at' => now()])->save();

            return;
        }

        $selector = $this->builder->build(
            $serverIds,
            $alert->config['sources'] ?? [],
            $alert->type === AlertType::Threshold ? ($alert->config['search'] ?? null) : null,
        );

        $window = (int) $alert->config['window_minutes'];
        $query = 'sum(count_over_time('.$selector.' ['.$window.'m]))';

        try {
            $response = Http::withHeaders(['X-Scope-OrgID' => 'team-'.$alert->team_id])
                ->timeout(10)
                ->get(rtrim(config('services.loki.url'), '/').'/loki/api/v1/query', ['query' => $query]);
        } catch (ConnectionException $exception) {
            Log::warning('Alert evaluation skipped: Loki unreachable', ['alert_id' => $alert->id, 'error' => $exception->getMessage()]);

            return;
        }

        if ($response->failed()) {
            Log::warning('Alert evaluation skipped: Loki error', ['alert_id' => $alert->id, 'status' => $response->status()]);

            return;
        }

        $count = (int) ($response->json('data.result.0.value.1') ?? 0);

        $breached = $alert->type === AlertType::Threshold
            ? $count > (int) $alert->config['threshold']
            : $count === 0;

        $newState = match (true) {
            $breached && $alert->state === AlertState::Ok => AlertState::Firing,
            ! $breached && $alert->state === AlertState::Firing => AlertState::Ok,
            default => null,
        };

        $alert->forceFill(['last_evaluated_at' => now()]);

        if ($newState === null) {
            $alert->save();

            return;
        }

        $alert->forceFill(['state' => $newState, 'last_state_change_at' => now()])->save();

        $event = $alert->events()->create(['state' => $newState, 'measured_value' => $count]);

        $this->notifier->notify($alert, $event);
    }
}
