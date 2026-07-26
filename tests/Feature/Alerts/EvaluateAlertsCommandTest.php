<?php

use App\Enums\AlertState;
use App\Models\Alert;
use App\Models\Server;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

test('the command evaluates enabled alerts and skips disabled ones', function () {
    Http::fake(['*/loki/api/v1/query*' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'vector', 'result' => []],
    ])]);

    $enabled = Alert::factory()->missingLogs()->create();
    Server::factory()->for($enabled->team)->create();
    $disabled = Alert::factory()->missingLogs()->create(['enabled' => false]);
    Server::factory()->for($disabled->team)->create();

    $this->artisan('alerts:evaluate')->assertSuccessful();

    expect($enabled->fresh()->state)->toBe(AlertState::Firing)
        ->and($disabled->fresh()->state)->toBe(AlertState::Ok);
});

test('one alert erroring does not stop the rest', function () {
    // First alert's team has servers but Loki explodes per-request; the command must continue.
    Http::fake(['*/loki/api/v1/query*' => Http::failedConnection()]);

    $first = Alert::factory()->missingLogs()->create();
    Server::factory()->for($first->team)->create();
    $second = Alert::factory()->missingLogs()->create();
    Server::factory()->for($second->team)->create();

    $this->artisan('alerts:evaluate')->assertSuccessful();

    // Both skipped their round (Loki down) but the command completed.
    expect($first->fresh()->state)->toBe(AlertState::Ok)
        ->and($second->fresh()->state)->toBe(AlertState::Ok);
});

test('the command is scheduled every minute', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'alerts:evaluate'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
