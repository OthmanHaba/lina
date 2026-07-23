<?php

use App\Models\Server;

test('newToken stores a sha256 hash and returns the plaintext once', function () {
    $server = Server::factory()->create();

    $plain = $server->newToken();

    expect(strlen($plain))->toBe(40)
        ->and($server->fresh()->token_hash)->toBe(hash('sha256', $plain));
});

test('findByToken resolves a server from the plaintext token', function () {
    $server = Server::factory()->create();
    $plain = $server->newToken();

    expect(Server::findByToken($plain)?->id)->toBe($server->id)
        ->and(Server::findByToken('wrong-token'))->toBeNull()
        ->and(Server::findByToken(null))->toBeNull();
});

test('status is derived from last_seen_at', function () {
    expect(Server::factory()->create(['last_seen_at' => null])->status())->toBe('pending')
        ->and(Server::factory()->online()->create()->status())->toBe('online')
        ->and(Server::factory()->offline()->create()->status())->toBe('offline');
});

test('bytesToday sums only today', function () {
    $server = Server::factory()->create();
    $server->usageRecords()->create(['date' => today(), 'bytes' => 100]);
    $server->usageRecords()->create(['date' => today()->subDay(), 'bytes' => 900]);

    expect($server->bytesToday())->toBe(100);
});
