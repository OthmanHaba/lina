<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Services\AlertEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class EvaluateAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate all enabled alerts against Loki and send state-change notifications';

    public function handle(AlertEvaluator $evaluator): int
    {
        Alert::where('enabled', true)
            ->with('team')
            ->get()
            ->each(function (Alert $alert) use ($evaluator) {
                try {
                    $evaluator->evaluate($alert);
                } catch (Throwable $exception) {
                    Log::error('Alert evaluation crashed', ['alert_id' => $alert->id, 'error' => $exception->getMessage()]);
                }
            });

        return self::SUCCESS;
    }
}
