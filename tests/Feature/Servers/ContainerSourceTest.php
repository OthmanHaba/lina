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

    $instructions = ContainerMountInstructions::for($source);

    expect($instructions['hostPath'])->toBe('/var/log/lina/'.$source->id)
        ->and($instructions['containerPath'])->toBe('/var/www/html/storage/logs')
        ->and($instructions['compose'])->toBe('- /var/log/lina/'.$source->id.':/var/www/html/storage/logs')
        ->and($instructions['dockerRun'])->toBe('-v /var/log/lina/'.$source->id.':/var/www/html/storage/logs')
        ->and($instructions['dokploy'])->toContain('Bind mount');
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
