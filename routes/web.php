<?php

use App\Http\Controllers\Alerts\AlertController;
use App\Http\Controllers\Alerts\NotificationChannelController;
use App\Http\Controllers\Dashboard\DashboardWidgetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Logs\LogController;
use App\Http\Controllers\Servers\InstallScriptController;
use App\Http\Controllers\Servers\LogSourceController;
use App\Http\Controllers\Servers\ServerController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('install/{token}', InstallScriptController::class)->name('servers.install');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::post('dashboard/widgets', [DashboardWidgetController::class, 'store'])->name('dashboard.widgets.store');
        Route::patch('dashboard/widgets/reorder', [DashboardWidgetController::class, 'reorder'])->name('dashboard.widgets.reorder');
        Route::patch('dashboard/widgets/{widget}', [DashboardWidgetController::class, 'update'])->name('dashboard.widgets.update');
        Route::delete('dashboard/widgets/{widget}', [DashboardWidgetController::class, 'destroy'])->name('dashboard.widgets.destroy');
        Route::get('servers', [ServerController::class, 'index'])->name('servers.index');
        Route::post('servers', [ServerController::class, 'store'])->name('servers.store');
        Route::get('servers/{server}', [ServerController::class, 'show'])->name('servers.show');
        Route::patch('servers/{server}', [ServerController::class, 'update'])->name('servers.update');
        Route::post('servers/{server}/token', [ServerController::class, 'regenerateToken'])->name('servers.token');
        Route::delete('servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
        Route::post('servers/{server}/sources', [LogSourceController::class, 'store'])->name('servers.sources.store');
        Route::patch('servers/{server}/sources/{source}', [LogSourceController::class, 'update'])->name('servers.sources.update');
        Route::delete('servers/{server}/sources/{source}', [LogSourceController::class, 'destroy'])->name('servers.sources.destroy');
        Route::get('logs', [LogController::class, 'index'])->name('logs.index');
        Route::get('logs/query', [LogController::class, 'query'])->name('logs.query');
        Route::post('channels', [NotificationChannelController::class, 'store'])->name('channels.store');
        Route::patch('channels/{channel}', [NotificationChannelController::class, 'update'])->name('channels.update');
        Route::delete('channels/{channel}', [NotificationChannelController::class, 'destroy'])->name('channels.destroy');
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::post('alerts', [AlertController::class, 'store'])->name('alerts.store');
        Route::patch('alerts/{alert}', [AlertController::class, 'update'])->name('alerts.update');
        Route::delete('alerts/{alert}', [AlertController::class, 'destroy'])->name('alerts.destroy');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
