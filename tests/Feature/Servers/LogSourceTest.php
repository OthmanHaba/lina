<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

function sourceOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);
    $server = Server::factory()->for($team)->create();

    return [$user, $team, $server];
}

test('a file source can be created', function () {
    [$user, $team, $server] = sourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'file',
            'name' => 'app-logs',
            'config' => ['paths' => ['/var/log/app/*.log']],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('log_sources', ['server_id' => $server->id, 'type' => 'file', 'name' => 'app-logs']);
});

test('file sources require at least one path', function () {
    [$user, $team, $server] = sourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'file',
            'name' => 'app-logs',
            'config' => ['paths' => []],
        ])
        ->assertSessionHasErrors('config.paths');
});

test('nginx sources get default paths', function () {
    [$user, $team, $server] = sourceOwner();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $server->id]), [
            'type' => 'nginx',
            'name' => 'nginx',
        ])
        ->assertRedirect();

    $source = $server->logSources()->firstOrFail();
    expect($source->config['access_log'])->toBe('/var/log/nginx/access.log')
        ->and($source->config['error_log'])->toBe('/var/log/nginx/error.log');
});

test('sources can be updated and toggled', function () {
    [$user, $team, $server] = sourceOwner();
    $source = $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->patch(route('servers.sources.update', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]), [
            'type' => 'systemd',
            'name' => 'journal',
            'config' => ['units' => ['nginx.service']],
            'enabled' => false,
        ])
        ->assertRedirect();

    $source->refresh();
    expect($source->config['units'])->toBe(['nginx.service'])
        ->and($source->enabled)->toBeFalse();
});

test('sources can be deleted', function () {
    [$user, $team, $server] = sourceOwner();
    $source = $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->delete(route('servers.sources.destroy', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]))
        ->assertRedirect();

    $this->assertDatabaseCount('log_sources', 0);
});

test('updating without a config key keeps the existing config', function () {
    [$user, $team, $server] = sourceOwner();
    $source = $server->logSources()->create(['type' => 'file', 'name' => 'app', 'config' => ['paths' => ['/var/log/app/*.log']], 'enabled' => true]);

    $this->actingAs($user)
        ->patch(route('servers.sources.update', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]), [
            'type' => 'file',
            'name' => 'app',
            'enabled' => false,
        ])
        ->assertRedirect();

    $source->refresh();
    expect($source->config['paths'])->toBe(['/var/log/app/*.log'])
        ->and($source->enabled)->toBeFalse();
});

test('sources of other teams servers are unreachable', function () {
    [$user, $team] = sourceOwner();
    $foreignServer = Server::factory()->create();

    $this->actingAs($user)
        ->post(route('servers.sources.store', ['current_team' => $team->slug, 'server' => $foreignServer->id]), [
            'type' => 'systemd',
            'name' => 'journal',
        ])
        ->assertNotFound();
});

test('a source must belong to the server in the url', function () {
    [$user, $team, $server] = sourceOwner();
    $otherServer = Server::factory()->for($team)->create();
    $source = $otherServer->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->delete(route('servers.sources.destroy', ['current_team' => $team->slug, 'server' => $server->id, 'source' => $source->id]))
        ->assertNotFound();
});
