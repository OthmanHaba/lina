<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function memberWithTeam(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('servers index lists only current team servers', function () {
    [$user, $team] = memberWithTeam();
    $mine = Server::factory()->for($team)->create();
    Server::factory()->create(); // other team

    $this->actingAs($user)
        ->get(route('servers.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/index')
            ->has('servers', 1)
            ->where('servers.0.id', $mine->id)
            ->where('servers.0.status', 'pending'),
        );
});

test('storing a server flashes the plaintext token once', function () {
    [$user, $team] = memberWithTeam();

    $response = $this->actingAs($user)
        ->post(route('servers.store', ['current_team' => $team->slug]), ['name' => 'web-1']);

    $server = Server::where('team_id', $team->id)->firstOrFail();
    $response->assertRedirect(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]));

    $plain = session('plainToken');
    expect($plain)->not->toBeNull()
        ->and($server->token_hash)->toBe(hash('sha256', $plain));
});

test('show page renders server with sources', function () {
    [$user, $team] = memberWithTeam();
    $server = Server::factory()->for($team)->online()->create();
    $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/show')
            ->where('server.id', $server->id)
            ->where('server.status', 'online')
            ->has('sources', 1)
            ->where('plainToken', null),
        );
});

test('servers of other teams 404', function () {
    [$user, $team] = memberWithTeam();
    $foreign = Server::factory()->create();

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $foreign->id]))
        ->assertNotFound();
});

test('token can be regenerated', function () {
    [$user, $team] = memberWithTeam();
    $server = Server::factory()->for($team)->create();
    $oldHash = $server->token_hash;

    $this->actingAs($user)
        ->post(route('servers.token', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertRedirect();

    expect($server->fresh()->token_hash)->not->toBe($oldHash)
        ->and(session('plainToken'))->not->toBeNull();
});

test('servers can be deleted with their sources', function () {
    [$user, $team] = memberWithTeam();
    $server = Server::factory()->for($team)->create();
    $server->logSources()->create(['type' => 'file', 'name' => 'app', 'config' => ['paths' => ['/tmp/a.log']], 'enabled' => true]);

    $this->actingAs($user)
        ->delete(route('servers.destroy', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertRedirect(route('servers.index', ['current_team' => $team->slug]));

    $this->assertDatabaseMissing('servers', ['id' => $server->id]);
    $this->assertDatabaseCount('log_sources', 0);
});

test('guests cannot access servers', function () {
    $team = Team::factory()->create();

    $this->get(route('servers.index', ['current_team' => $team->slug]))
        ->assertRedirect(route('login'));
});
