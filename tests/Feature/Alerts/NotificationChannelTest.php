<?php

use App\Enums\TeamRole;
use App\Models\NotificationChannel;
use App\Models\Team;
use App\Models\User;

function channelOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

test('email channels can be created', function () {
    [$user, $team] = channelOwner();

    $this->actingAs($user)
        ->post(route('channels.store', ['current_team' => $team->slug]), [
            'type' => 'email',
            'name' => 'ops-mail',
            'config' => ['address' => 'ops@example.com'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('notification_channels', ['team_id' => $team->id, 'type' => 'email', 'name' => 'ops-mail']);
});

test('webhook channels require a valid http url', function () {
    [$user, $team] = channelOwner();

    $this->actingAs($user)
        ->post(route('channels.store', ['current_team' => $team->slug]), [
            'type' => 'webhook',
            'name' => 'hook',
            'config' => ['url' => 'not-a-url'],
        ])
        ->assertSessionHasErrors('config.url');
});

test('telegram channels require a chat id', function () {
    [$user, $team] = channelOwner();

    $this->actingAs($user)
        ->post(route('channels.store', ['current_team' => $team->slug]), [
            'type' => 'telegram',
            'name' => 'tg',
            'config' => [],
        ])
        ->assertSessionHasErrors('config.chat_id');
});

test('channels can be updated and re-enabled with failure reset', function () {
    [$user, $team] = channelOwner();
    $channel = NotificationChannel::factory()->create(['team_id' => $team->id, 'enabled' => false, 'failure_count' => 10]);

    $this->actingAs($user)
        ->patch(route('channels.update', ['current_team' => $team->slug, 'channel' => $channel->id]), [
            'type' => 'email',
            'name' => 'renamed',
            'config' => ['address' => 'new@example.com'],
            'enabled' => true,
        ])
        ->assertRedirect();

    $channel->refresh();
    expect($channel->name)->toBe('renamed')
        ->and($channel->enabled)->toBeTrue()
        ->and($channel->failure_count)->toBe(0);
});

test('channels can be deleted', function () {
    [$user, $team] = channelOwner();
    $channel = NotificationChannel::factory()->create(['team_id' => $team->id]);

    $this->actingAs($user)
        ->delete(route('channels.destroy', ['current_team' => $team->slug, 'channel' => $channel->id]))
        ->assertRedirect();

    $this->assertDatabaseCount('notification_channels', 0);
});

test('channels of other teams are unreachable', function () {
    [$user, $team] = channelOwner();
    $foreign = NotificationChannel::factory()->create();

    $this->actingAs($user)
        ->delete(route('channels.destroy', ['current_team' => $team->slug, 'channel' => $foreign->id]))
        ->assertNotFound();

    $this->assertDatabaseCount('notification_channels', 1);
});
