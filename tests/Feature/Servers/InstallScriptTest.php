<?php

use App\Models\Server;

test('the install script embeds the token and base url', function () {
    $server = Server::factory()->create();
    $plain = $server->newToken();

    $response = $this->get('/install/'.$plain);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    $script = $response->getContent();
    expect($script)
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('LINA_TOKEN='.$plain)
        ->toContain('LINA_BASE_URL='.rtrim(config('app.url'), '/'))
        ->toContain('lina-agent-update')
        ->toContain('apt.grafana.com')
        ->toContain('rpm.grafana.com')
        // The alloy user must be able to read the config the root-owned updater writes.
        ->toContain('chmod 0644 /etc/alloy/config.alloy');
});

test('unknown tokens 404', function () {
    $this->get('/install/not-a-real-token')->assertNotFound();
});
