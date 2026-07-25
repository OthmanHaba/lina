<?php

use App\Http\Controllers\Agent\AgentConfigController;
use App\Http\Middleware\AuthenticateAgent;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateAgent::class)->group(function () {
    Route::get('agent/config', AgentConfigController::class)->name('agent.config');
});
