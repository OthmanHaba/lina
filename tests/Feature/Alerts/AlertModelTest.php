<?php

use App\Enums\AlertState;
use App\Models\Alert;
use App\Models\NotificationChannel;

test('channels record failures and auto-disable at 10', function () {
    $channel = NotificationChannel::factory()->create();

    for ($i = 0; $i < 9; $i++) {
        $channel->recordFailure();
    }

    expect($channel->fresh()->enabled)->toBeTrue()
        ->and($channel->fresh()->failure_count)->toBe(9);

    $channel->recordFailure();

    expect($channel->fresh()->enabled)->toBeFalse()
        ->and($channel->fresh()->failure_count)->toBe(10);
});

test('a success resets the failure count', function () {
    $channel = NotificationChannel::factory()->create(['failure_count' => 4]);

    $channel->recordSuccess();

    expect($channel->fresh()->failure_count)->toBe(0);
});

test('alerts sync channels and cascade events on delete', function () {
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id]);

    $alert->channels()->sync([$channel->id]);
    $alert->events()->create(['state' => AlertState::Firing, 'measured_value' => 7]);

    expect($alert->channels)->toHaveCount(1)
        ->and($alert->events)->toHaveCount(1);

    $alert->delete();

    $this->assertDatabaseCount('alert_events', 0);
    $this->assertDatabaseCount('alert_channel', 0);
});

test('deleting a channel detaches it from alerts but keeps the alert', function () {
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id]);
    $alert->channels()->sync([$channel->id]);

    $channel->delete();

    expect($alert->fresh())->not->toBeNull()
        ->and($alert->fresh()->channels)->toHaveCount(0);
});
