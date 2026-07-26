<?php

use App\Enums\AlertState;
use App\Mail\AlertStateChangedMail;
use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Services\AlertEvaluator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function lokiCount(int $count): void
{
    Http::fake(['*/loki/api/v1/query*' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'vector', 'result' => [['metric' => [], 'value' => [1700000000, (string) $count]]]],
    ])]);
}

function thresholdAlert(int $threshold = 5): Alert
{
    $alert = Alert::factory()->create(['config' => ['servers' => [], 'sources' => [], 'search' => 'error', 'threshold' => $threshold, 'window_minutes' => 5]]);
    Server::factory()->for($alert->team)->create();

    return $alert;
}

test('a breached threshold flips ok to firing with event and notification', function () {
    Mail::fake();
    lokiCount(9);
    $alert = thresholdAlert(threshold: 5);
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id]);
    $alert->channels()->sync([$channel->id]);

    app(AlertEvaluator::class)->evaluate($alert);

    $alert->refresh();
    expect($alert->state)->toBe(AlertState::Firing)
        ->and($alert->last_state_change_at)->not->toBeNull()
        ->and($alert->events()->count())->toBe(1)
        ->and($alert->events()->first()->measured_value)->toBe(9);
    Mail::assertSent(AlertStateChangedMail::class);
});

test('a firing alert below threshold recovers with notification', function () {
    Mail::fake();
    lokiCount(1);
    $alert = thresholdAlert(threshold: 5);
    $alert->update(['state' => AlertState::Firing]);
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id]);
    $alert->channels()->sync([$channel->id]);

    app(AlertEvaluator::class)->evaluate($alert);

    expect($alert->fresh()->state)->toBe(AlertState::Ok)
        ->and($alert->events()->first()->state)->toBe(AlertState::Ok);
    Mail::assertSent(AlertStateChangedMail::class);
});

test('steady states never notify', function () {
    Mail::fake();
    lokiCount(1);
    $alert = thresholdAlert(threshold: 5);
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id]);
    $alert->channels()->sync([$channel->id]);

    app(AlertEvaluator::class)->evaluate($alert);

    expect($alert->fresh()->state)->toBe(AlertState::Ok)
        ->and($alert->events()->count())->toBe(0)
        ->and($alert->fresh()->last_evaluated_at)->not->toBeNull();
    Mail::assertNothingSent();
});

test('missing_logs fires on zero count', function () {
    lokiCount(0);
    $alert = Alert::factory()->missingLogs()->create();
    Server::factory()->for($alert->team)->create();

    app(AlertEvaluator::class)->evaluate($alert);

    expect($alert->fresh()->state)->toBe(AlertState::Firing);
});

test('an empty result vector counts as zero', function () {
    Http::fake(['*/loki/api/v1/query*' => Http::response([
        'status' => 'success',
        'data' => ['resultType' => 'vector', 'result' => []],
    ])]);
    $alert = Alert::factory()->missingLogs()->create();
    Server::factory()->for($alert->team)->create();

    app(AlertEvaluator::class)->evaluate($alert);

    expect($alert->fresh()->state)->toBe(AlertState::Firing);
});

test('alerts with no effective servers are skipped without querying loki', function () {
    Http::fake();
    $alert = Alert::factory()->missingLogs()->create(); // team has NO servers

    app(AlertEvaluator::class)->evaluate($alert);

    expect($alert->fresh()->state)->toBe(AlertState::Ok);
    Http::assertNothingSent();
});

test('foreign server ids in config are stripped', function () {
    lokiCount(0);
    $alert = Alert::factory()->missingLogs()->create();
    $mine = Server::factory()->for($alert->team)->create();
    $foreign = Server::factory()->create();
    $alert->update(['config' => array_merge($alert->config, ['servers' => [$mine->id, $foreign->id]])]);

    app(AlertEvaluator::class)->evaluate($alert);

    Http::assertSent(function ($request) use ($mine, $foreign) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);

        return str_contains($params['query'], '^('.$mine->id.')$')
            && ! str_contains($params['query'], (string) $foreign->id);
    });
});

test('the org id always comes from the alert team', function () {
    lokiCount(9);
    $alert = thresholdAlert();

    app(AlertEvaluator::class)->evaluate($alert);

    Http::assertSent(fn ($request) => $request->header('X-Scope-OrgID')[0] === 'team-'.$alert->team_id);
});

test('loki failure skips the round without state change or notification', function () {
    Mail::fake();
    Http::fake(['*/loki/api/v1/query*' => Http::failedConnection()]);
    $alert = thresholdAlert();
    $alert->update(['state' => AlertState::Firing]);

    app(AlertEvaluator::class)->evaluate($alert);

    expect($alert->fresh()->state)->toBe(AlertState::Firing)
        ->and($alert->events()->count())->toBe(0);
    Mail::assertNothingSent();
});
