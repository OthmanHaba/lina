<?php

use App\Enums\AlertState;
use App\Mail\AlertStateChangedMail;
use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Services\AlertNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function firingEventFor(Alert $alert)
{
    return $alert->events()->create(['state' => AlertState::Firing, 'measured_value' => 42]);
}

test('email channels get a mailable', function () {
    Mail::fake();
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id, 'config' => ['address' => 'ops@example.com']]);
    $alert->channels()->sync([$channel->id]);

    app(AlertNotifier::class)->notify($alert, firingEventFor($alert));

    Mail::assertSent(AlertStateChangedMail::class, fn ($mail) => $mail->hasTo('ops@example.com'));
});

test('webhook channels get the documented json payload', function () {
    Http::fake(['hooks.example.test/*' => Http::response('ok')]);
    $alert = Alert::factory()->create(['name' => 'spike']);
    $channel = NotificationChannel::factory()->webhook('https://hooks.example.test/lina')->create(['team_id' => $alert->team_id]);
    $alert->channels()->sync([$channel->id]);

    app(AlertNotifier::class)->notify($alert, firingEventFor($alert));

    Http::assertSent(function ($request) use ($alert) {
        return $request->url() === 'https://hooks.example.test/lina'
            && $request['alert']['id'] === $alert->id
            && $request['alert']['name'] === 'spike'
            && $request['state'] === 'firing'
            && $request['measured_value'] === 42
            && $request['window_minutes'] === $alert->config['window_minutes']
            && str_contains($request['url'], '/logs');
    });
});

test('telegram channels call the bot api with the chat id', function () {
    config(['services.telegram.bot_token' => 'BOT123']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->telegram('9999')->create(['team_id' => $alert->team_id]);
    $alert->channels()->sync([$channel->id]);

    app(AlertNotifier::class)->notify($alert, firingEventFor($alert));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/botBOT123/sendMessage')
        && $request['chat_id'] === '9999'
        && str_contains($request['text'], $alert->name));
});

test('a failing channel records the failure without blocking others', function () {
    Mail::fake();
    Http::fake(['hooks.example.test/*' => Http::response('boom', 500)]);
    $alert = Alert::factory()->create();
    $bad = NotificationChannel::factory()->webhook('https://hooks.example.test/bad')->create(['team_id' => $alert->team_id]);
    $good = NotificationChannel::factory()->create(['team_id' => $alert->team_id, 'config' => ['address' => 'ok@example.com']]);
    $alert->channels()->sync([$bad->id, $good->id]);

    app(AlertNotifier::class)->notify($alert, firingEventFor($alert));

    expect($bad->fresh()->failure_count)->toBe(1);
    Mail::assertSent(AlertStateChangedMail::class);
});

test('disabled channels are skipped', function () {
    Mail::fake();
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->create(['team_id' => $alert->team_id, 'enabled' => false]);
    $alert->channels()->sync([$channel->id]);

    app(AlertNotifier::class)->notify($alert, firingEventFor($alert));

    Mail::assertNothingSent();
});

test('a delivery success resets the failure streak', function () {
    Http::fake(['hooks.example.test/*' => Http::response('ok')]);
    $alert = Alert::factory()->create();
    $channel = NotificationChannel::factory()->webhook()->create(['team_id' => $alert->team_id, 'failure_count' => 3]);
    $alert->channels()->sync([$channel->id]);

    app(AlertNotifier::class)->notify($alert, firingEventFor($alert));

    expect($channel->fresh()->failure_count)->toBe(0);
});
