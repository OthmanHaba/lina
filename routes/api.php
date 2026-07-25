<?php

use App\Http\Controllers\Agent\AgentConfigController;
use App\Http\Controllers\Agent\IngestController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateAgent::class)->group(function () {
    Route::get('agent/config', AgentConfigController::class)->name('agent.config');
    Route::post('agent/push', IngestController::class)
        ->middleware('throttle:ingest')
        ->name('agent.push');
});
