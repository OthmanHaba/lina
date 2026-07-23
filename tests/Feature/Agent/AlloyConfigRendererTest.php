<?php

use App\Models\LogSource;
use App\Models\Server;
use App\Services\AlloyConfigRenderer;

test('base config always contains an authenticated loki writer with the server label', function () {
    $server = Server::factory()->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('loki.write "lina"')
        ->toContain('url = "'.config('app.url').'/api/agent/push"')
        ->toContain('bearer_token = sys.env("LINA_TOKEN")')
        ->toContain('server_id = "'.$server->id.'"');
});

test('systemd sources render a journal block with unit filtering', function () {
    $server = Server::factory()->create();
    $source = LogSource::factory()->systemd(['nginx.service', 'php8.4-fpm.service'])->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('loki.source.journal "src_'.$source->id.'"')
        ->toContain('loki.relabel "src_'.$source->id.'"')
        // preg_quote escapes . and -, then quote() doubles backslashes for Alloy string literals
        ->toContain('regex = "nginx\\\\.service|php8\\\\.4\\\\-fpm\\\\.service"')
        ->toContain('source = "'.$source->name.'"');
});

test('systemd sources without units skip the keep rule', function () {
    $server = Server::factory()->create();
    LogSource::factory()->systemd()->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)->toContain('loki.source.journal')
        ->not->toContain('action = "keep"');
});

test('file sources render file matchers per path', function () {
    $server = Server::factory()->create();
    $source = LogSource::factory()->file(['/var/log/app/*.log', '/srv/worker.log'])->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('local.file_match "src_'.$source->id.'"')
        ->toContain('"/var/log/app/*.log"')
        ->toContain('"/srv/worker.log"')
        ->toContain('loki.source.file "src_'.$source->id.'"');
});

test('nginx sources render as file sources with the configured paths', function () {
    $server = Server::factory()->create();
    LogSource::factory()->nginx()->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('"/var/log/nginx/access.log"')
        ->toContain('"/var/log/nginx/error.log"');
});

test('docker sources render docker discovery with optional container filter', function () {
    $server = Server::factory()->create();
    $source = LogSource::factory()->docker(['web', 'worker'])->for($server)->create();

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('discovery.docker "src_'.$source->id.'"')
        ->toContain('loki.source.docker "src_'.$source->id.'"')
        ->toContain('regex = "/?(web|worker)"');
});

test('disabled sources are omitted', function () {
    $server = Server::factory()->create();
    LogSource::factory()->file()->for($server)->create(['enabled' => false]);

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)->not->toContain('loki.source.file');
});
