<?php

use App\Enums\TeamRole;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\AlloyConfigRenderer;
use Inertia\Testing\AssertableInertia as Assert;

test('configStatus is synced when the synced hash matches the rendered config', function () {
    $server = Server::factory()->create();
    $hash = sha1(app(AlloyConfigRenderer::class)->render($server));
    $server->forceFill(['synced_config_hash' => $hash, 'config_synced_at' => now(), 'last_poll_at' => now()])->save();

    expect($server->configStatus())->toBe('synced');
});

test('configStatus flips to syncing when the config changes and polls are recent', function () {
    $server = Server::factory()->create();
    $hash = sha1(app(AlloyConfigRenderer::class)->render($server));
    $server->forceFill(['synced_config_hash' => $hash, 'last_poll_at' => now()])->save();

    $server->logSources()->create(['type' => 'systemd', 'name' => 'journal', 'config' => [], 'enabled' => true]);

    expect($server->fresh()->configStatus())->toBe('syncing');
});

test('configStatus is unknown when the agent is silent', function () {
    expect(Server::factory()->create()->configStatus())->toBe('unknown')
        ->and(Server::factory()->create(['last_poll_at' => now()->subMinutes(10)])->configStatus())->toBe('unknown');
});

test('usageSeries returns 14 zero-filled entries oldest first', function () {
    $server = Server::factory()->create();
    $server->usageRecords()->create(['date' => today(), 'bytes' => 100]);
    $server->usageRecords()->create(['date' => today()->subDays(3), 'bytes' => 50]);
    $server->usageRecords()->create(['date' => today()->subDays(20), 'bytes' => 999]); // outside window

    $series = $server->usageSeries();

    expect($series)->toHaveCount(14)
        ->and($series[0]['date'])->toBe(today()->subDays(13)->toDateString())
        ->and($series[13])->toBe(['date' => today()->toDateString(), 'bytes' => 100])
        ->and($series[10]['bytes'])->toBe(50)
        ->and(collect($series)->sum('bytes'))->toBe(150);
});

test('the server page includes the health prop', function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);
    $server = Server::factory()->for($team)->online()->create(['last_poll_at' => now(), 'last_push_at' => now()]);

    $this->actingAs($user)
        ->get(route('servers.show', ['current_team' => $team->slug, 'server' => $server->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('servers/show')
            ->where('health.configStatus', 'syncing')
            ->has('health.usage', 14)
            ->whereNot('health.lastPollAt', null)
            ->whereNot('health.lastPushAt', null),
        );
});
