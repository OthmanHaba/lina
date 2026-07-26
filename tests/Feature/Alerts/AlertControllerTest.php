<?php

use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\NotificationChannel;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function alertOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('the alerts page renders with team-scoped props', function () {
    [$user, $team] = alertOwner();
    $alert = Alert::factory()->create(['team_id' => $team->id]);
    NotificationChannel::factory()->create(['team_id' => $team->id]);
    Server::factory()->for($team)->create();
    Alert::factory()->create(); // foreign

    $this->actingAs($user)
        ->get(route('alerts.index', ['current_team' => $team->slug]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('alerts/index')
            ->has('alerts', 1)
            ->where('alerts.0.id', $alert->id)
            ->has('channels', 1)
            ->has('servers', 1),
        );
});

test('threshold alerts can be created with channels attached', function () {
    [$user, $team] = alertOwner();
    $channel = NotificationChannel::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->post(route('alerts.store', ['current_team' => $team->slug]), [
            'name' => 'error-spike',
            'type' => 'threshold',
            'config' => ['servers' => [], 'sources' => [], 'search' => 'error', 'threshold' => 10, 'window_minutes' => 5],
            'channels' => [$channel->id],
        ])
        ->assertRedirect();

    $alert = Alert::where('team_id', $team->id)->firstOrFail();
    expect($alert->channels()->count())->toBe(1)
        ->and($alert->config['threshold'])->toBe(10);
});

test('threshold alerts require a threshold', function () {
    [$user, $team] = alertOwner();

    $this->actingAs($user)
        ->post(route('alerts.store', ['current_team' => $team->slug]), [
            'name' => 'bad',
            'type' => 'threshold',
            'config' => ['window_minutes' => 5],
            'channels' => [],
        ])
        ->assertSessionHasErrors('config.threshold');
});

test('missing_logs alerts need no threshold or search', function () {
    [$user, $team] = alertOwner();

    $this->actingAs($user)
        ->post(route('alerts.store', ['current_team' => $team->slug]), [
            'name' => 'silence',
            'type' => 'missing_logs',
            'config' => ['window_minutes' => 15],
            'channels' => [],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('alerts', ['team_id' => $team->id, 'type' => 'missing_logs']);
});

test('foreign channel ids are rejected', function () {
    [$user, $team] = alertOwner();
    $foreignChannel = NotificationChannel::factory()->create();

    $this->actingAs($user)
        ->post(route('alerts.store', ['current_team' => $team->slug]), [
            'name' => 'sneaky',
            'type' => 'missing_logs',
            'config' => ['window_minutes' => 15],
            'channels' => [$foreignChannel->id],
        ])
        ->assertSessionHasErrors('channels.0');
});

test('alerts can be updated including channel sync and toggle', function () {
    [$user, $team] = alertOwner();
    $alert = Alert::factory()->create(['team_id' => $team->id]);
    $channel = NotificationChannel::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->patch(route('alerts.update', ['current_team' => $team->slug, 'alert' => $alert->id]), [
            'name' => 'renamed',
            'type' => 'threshold',
            'enabled' => false,
            'config' => ['servers' => [], 'sources' => [], 'search' => 'fatal', 'threshold' => 3, 'window_minutes' => 10],
            'channels' => [$channel->id],
        ])
        ->assertRedirect();

    $alert->refresh();
    expect($alert->name)->toBe('renamed')
        ->and($alert->enabled)->toBeFalse()
        ->and($alert->channels()->count())->toBe(1);
});

test('alerts of other teams are unreachable', function () {
    [$user, $team] = alertOwner();
    $foreign = Alert::factory()->create();

    $this->actingAs($user)
        ->delete(route('alerts.destroy', ['current_team' => $team->slug, 'alert' => $foreign->id]))
        ->assertNotFound();
});

test('alerts can be deleted', function () {
    [$user, $team] = alertOwner();
    $alert = Alert::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->delete(route('alerts.destroy', ['current_team' => $team->slug, 'alert' => $alert->id]))
        ->assertRedirect();

    $this->assertDatabaseCount('alerts', 0);
});
