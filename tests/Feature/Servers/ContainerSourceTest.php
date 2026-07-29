<?php

use App\Enums\LogSourceType;
use App\Enums\TeamRole;
use App\Models\LogSource;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\AlloyConfigRenderer;
use App\Services\ContainerMountInstructions;

function containerSourceOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);
    $server = Server::factory()->for($team)->create();

    return [$user, $team, $server];
}

test('renders a container source as a file tail on the fixed host path', function () {
    [, , $server] = containerSourceOwner();
    $source = LogSource::factory()->for($server)->create([
        'type' => LogSourceType::Container,
        'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ]);

    $config = app(AlloyConfigRenderer::class)->render($server);

    expect($config)
        ->toContain('/var/log/lina/'.$source->id.'/*.log')
        ->toContain('loki.source.file "src_'.$source->id.'"');
});

test('builds mount instructions from the source id and in-container path', function () {
    [, , $server] = containerSourceOwner();
    $source = LogSource::factory()->for($server)->create([
        'type' => LogSourceType::Container,
        'name' => 'store-app',
        'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
    ]);

    $i = ContainerMountInstructions::for($source);
    $host = '/var/log/lina/'.$source->id;

    expect($i['hostPath'])->toBe($host)
        ->and($i['containerPath'])->toBe('/var/www/html/storage/logs')
        ->and($i['compose'])->toBe("- {$host}:/var/www/html/storage/logs")
        ->and($i['dockerRun'])->toBe("-v {$host}:/var/www/html/storage/logs")
        ->and($i['prepare'])->toBe("sudo mkdir -p {$host} && sudo chmod 777 {$host}")
        ->and($i['recreate'])->toBe('docker compose up -d')
        ->and($i['tailGlob'])->toBe("{$host}/*.log")
        ->and($i)->not->toHaveKey('dokploy');
});

it('rejects an in-container path containing a glob', function () {
    [$user, $team, $server] = containerSourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'container', 'name' => 'store-app',
            'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs/*.log'],
        ])->assertSessionHasErrors('config.in_container_path');
});

it('rejects an in-container path with a trailing slash', function () {
    [$user, $team, $server] = containerSourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'container', 'name' => 'store-app',
            'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs/'],
        ])->assertSessionHasErrors('config.in_container_path');
});

it('accepts a clean directory path', function () {
    [$user, $team, $server] = containerSourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'container', 'name' => 'store-app',
            'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
        ])->assertRedirect();
});

test('creates a container source with valid config', function () {
    [$user, $team, $server] = containerSourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'container', 'name' => 'store-app',
            'config' => ['container' => 'store-app', 'in_container_path' => '/var/www/html/storage/logs'],
        ])->assertRedirect();

    expect(LogSource::where('server_id', $server->id)->where('type', LogSourceType::Container)->exists())->toBeTrue();
});

test('rejects a container source with a relative in-container path', function () {
    [$user, $team, $server] = containerSourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'container', 'name' => 'store-app',
            'config' => ['container' => 'store-app', 'in_container_path' => 'storage/logs'],
        ])->assertSessionHasErrors('config.in_container_path');
});

test('rejects a container source missing the container name', function () {
    [$user, $team, $server] = containerSourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'container', 'name' => 'store-app',
            'config' => ['in_container_path' => '/var/www/html/storage/logs'],
        ])->assertSessionHasErrors('config.container');
});
