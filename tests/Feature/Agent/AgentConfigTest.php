<?php

use App\Models\Server;

function agentServer(): array
{
    $server = Server::factory()->create();
    $plain = $server->newToken();

    return [$server, $plain];
}

test('agents fetch their rendered config with a bearer token', function () {
    [$server, $plain] = agentServer();

    $response = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->get('/api/agent/config');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('ETag');

    expect($response->getContent())->toContain('loki.write "lina"');
});

test('a matching If-None-Match returns 304', function () {
    [$server, $plain] = agentServer();

    $etag = $this->withHeader('Authorization', 'Bearer '.$plain)
        ->get('/api/agent/config')
        ->headers->get('ETag');

    $this->withHeaders(['Authorization' => 'Bearer '.$plain, 'If-None-Match' => $etag])
        ->get('/api/agent/config')
        ->assertStatus(304);
});

test('config polls record agent metadata and liveness', function () {
    [$server, $plain] = agentServer();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$plain,
        'X-Lina-Hostname' => 'web-1.example.com',
        'X-Lina-Agent-Version' => 'alloy 1.7.1',
    ])->get('/api/agent/config')->assertOk();

    $server->refresh();
    expect($server->hostname)->toBe('web-1.example.com')
        ->and($server->agent_version)->toBe('alloy 1.7.1')
        ->and($server->last_seen_at)->not->toBeNull()
        ->and($server->status())->toBe('online');
});

test('missing or invalid tokens are rejected', function () {
    $this->get('/api/agent/config')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer nope')
        ->get('/api/agent/config')
        ->assertUnauthorized();
});
