<?php

use App\Models\Server;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\Http;

function pushAs(string $token, string $body = 'raw-snappy-protobuf')
{
    return test()->call(
        'POST',
        '/api/agent/push',
        server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/x-protobuf',
        ],
        content: $body,
    );
}

test('pushes over the rate limit get a 429', function () {
    config(['services.loki.ingest_rpm' => 2]);
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain)->assertNoContent();
    pushAs($plain)->assertNoContent();
    pushAs($plain)->assertStatus(429);
});

test('pushes are forwarded verbatim to loki with the team org id', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain, 'the-raw-body')->assertNoContent();

    Http::assertSent(function ($request) use ($server) {
        return str_ends_with($request->url(), '/loki/api/v1/push')
            && $request->header('X-Scope-OrgID')[0] === 'team-'.$server->team_id
            && $request->body() === 'the-raw-body';
    });
});

test('the org id always comes from the token, never the request', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $serverA = Server::factory()->create();
    $plain = $serverA->newToken();
    $serverB = Server::factory()->create(); // different team

    // Even a malicious body/header cannot change tenancy.
    test()->call('POST', '/api/agent/push', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
        'HTTP_X_SCOPE_ORGID' => 'team-'.$serverB->team_id,
        'CONTENT_TYPE' => 'application/x-protobuf',
    ], content: 'body')->assertNoContent();

    Http::assertSent(fn ($request) => $request->header('X-Scope-OrgID')[0] === 'team-'.$serverA->team_id);
});

test('usage bytes accumulate per server per day', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('', 204)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain, str_repeat('a', 100));
    pushAs($plain, str_repeat('b', 50));

    $record = UsageRecord::where('server_id', $server->id)->whereDate('date', today())->firstOrFail();
    expect($record->bytes)->toBe(150);
});

test('loki errors surface to the agent for retry', function () {
    Http::fake(['*/loki/api/v1/push' => Http::response('error', 503)]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain)->assertStatus(503);
});

test('pushes without a valid token are rejected and never forwarded', function () {
    Http::fake();

    pushAs('bad-token')->assertUnauthorized();

    Http::assertNothingSent();
});

test('an unreachable loki returns 503 to the agent', function () {
    Http::fake(['*/loki/api/v1/push' => Http::failedConnection()]);
    $server = Server::factory()->create();
    $plain = $server->newToken();

    pushAs($plain)->assertStatus(503);
});
