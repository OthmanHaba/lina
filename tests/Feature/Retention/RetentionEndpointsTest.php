<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\LokiOverridesWriter;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['services.loki.overrides_path' => storage_path('framework/testing/overrides-test.yaml')]);
    @unlink(storage_path('framework/testing/overrides-test.yaml'));
});

function retentionOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('team retention can be updated and rewrites the overrides file', function () {
    [$user, $team] = retentionOwner();

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 90])
        ->assertRedirect();

    expect($team->fresh()->retention_days)->toBe(90)
        ->and(file_get_contents(config('services.loki.overrides_path')))->toContain('retention_period: 90d');
});

test('team retention is validated to 1-365', function () {
    [$user, $team] = retentionOwner();

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 0])
        ->assertSessionHasErrors('retention_days');

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 366])
        ->assertSessionHasErrors('retention_days');
});

test('team updates without retention_days keep the current value', function () {
    [$user, $team] = retentionOwner();
    $team->update(['retention_days' => 60]);

    $this->actingAs($user)
        ->patch(route('teams.update', $team), ['name' => 'Renamed'])
        ->assertRedirect();

    expect($team->fresh()->retention_days)->toBe(60);
});

test('members cannot change team retention', function () {
    [, $team] = retentionOwner();
    $member = User::factory()->create();
    $team->members()->attach($member, ['role' => TeamRole::Member->value]);
    $member->update(['current_team_id' => $team->id]);

    $this->actingAs($member)
        ->patch(route('teams.update', $team), ['name' => $team->name, 'retention_days' => 5])
        ->assertForbidden();
});

test('the team edit page includes retentionDays', function () {
    [$user, $team] = retentionOwner();

    $this->actingAs($user)
        ->get(route('teams.edit', $team))
        ->assertInertia(fn (Assert $page) => $page->where('team.retentionDays', 30));
});

test('server retention override can be set and cleared', function () {
    [$user, $team] = retentionOwner();
    $server = Server::factory()->for($team)->create();

    $this->actingAs($user)
        ->patch(route('servers.update', ['current_team' => $team->slug, 'server' => $server->id]), ['retention_days' => 7])
        ->assertRedirect();

    expect($server->fresh()->retention_days)->toBe(7)
        ->and(file_get_contents(config('services.loki.overrides_path')))->toContain('period: 7d');

    $this->actingAs($user)
        ->patch(route('servers.update', ['current_team' => $team->slug, 'server' => $server->id]), ['retention_days' => null])
        ->assertRedirect();

    expect($server->fresh()->retention_days)->toBeNull()
        ->and(file_get_contents(config('services.loki.overrides_path')))->not->toContain('retention_stream');
});

test('foreign servers cannot be updated', function () {
    [$user, $team] = retentionOwner();
    $foreign = Server::factory()->create();

    $this->actingAs($user)
        ->patch(route('servers.update', ['current_team' => $team->slug, 'server' => $foreign->id]), ['retention_days' => 7])
        ->assertNotFound();
});

test('deleting a server rewrites the overrides file', function () {
    [$user, $team] = retentionOwner();
    $server = Server::factory()->for($team)->create(['retention_days' => 7]);
    app(LokiOverridesWriter::class)->write();
    expect(file_get_contents(config('services.loki.overrides_path')))->toContain('retention_stream');

    $this->actingAs($user)
        ->delete(route('servers.destroy', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertRedirect();

    expect(file_get_contents(config('services.loki.overrides_path')))->not->toContain('retention_stream');
});

test('deleting a team rewrites the overrides file without it', function () {
    [$user, $team] = retentionOwner();
    app(LokiOverridesWriter::class)->write();
    expect(file_get_contents(config('services.loki.overrides_path')))->toContain("team-{$team->id}:");

    $this->actingAs($user)
        ->delete(route('teams.destroy', $team), ['name' => $team->name])
        ->assertRedirect();

    expect(file_get_contents(config('services.loki.overrides_path')))->not->toContain("team-{$team->id}:");
});

test('the server page includes retention props', function () {
    [$user, $team] = retentionOwner();
    $server = Server::factory()->for($team)->create(['retention_days' => 7]);

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('server.retentionDays', 7)
            ->where('teamRetentionDays', 30),
        );
});

test('creating a team rewrites the overrides file with its entry', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('teams.store'), ['name' => 'Fresh Team'])
        ->assertRedirect();

    $team = Team::where('name', 'Fresh Team')->firstOrFail();
    expect(file_get_contents(config('services.loki.overrides_path')))->toContain("team-{$team->id}:");
});
