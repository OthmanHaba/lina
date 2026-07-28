<?php

use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function healthAgent(): array
{
    $server = Server::factory()->create();
    $plain = $server->newToken();

    return [$server, $plain];
}

function healthPush(string $token)
{
    return test()->call('POST', '/api/agent/push', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        'CONTENT_TYPE' => 'application/x-protobuf',
    ], content: 'body');
}

test('config polls bump last_poll_at but not last_push_at', function () {
    [$server, $plain] = healthAgent();

    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->get('/api/agent/config')
        ->assertOk();

    $server->refresh();
    expect($server->last_poll_at)->not->toBeNull()
        ->and($server->last_push_at)->toBeNull();
});

test('pushes bump last_push_at but not last_poll_at', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    [$server, $plain] = healthAgent();

    healthPush($plain)->assertNoContent();

    $server->refresh();
    expect($server->last_push_at)->not->toBeNull()
        ->and($server->last_poll_at)->toBeNull();
});

test('poll bumps are throttled to 30 seconds', function () {
    [$server, $plain] = healthAgent();

    Carbon::setTestNow('2026-07-28 10:00:00');
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    $first = $server->fresh()->last_poll_at;

    Carbon::setTestNow('2026-07-28 10:00:10');
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    expect($server->fresh()->last_poll_at)->toEqual($first);

    Carbon::setTestNow('2026-07-28 10:00:45');
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    expect($server->fresh()->last_poll_at)->toBeGreaterThan($first);

    Carbon::setTestNow();
});

test('a 304 poll records config sync while a 200 does not', function () {
    [$server, $plain] = healthAgent();

    $response = $this->withHeader('Authorization', 'Bearer '.$plain)->get('/api/agent/config');
    $etag = $response->headers->get('ETag');

    $server->refresh();
    expect($server->synced_config_hash)->toBeNull()
        ->and($server->config_synced_at)->toBeNull();

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'If-None-Match' => $etag])
        ->get('/api/agent/config')
        ->assertStatus(304);

    $server->refresh();
    expect($server->synced_config_hash)->toBe(trim($etag, '"'))
        ->and($server->config_synced_at)->not->toBeNull();
});
