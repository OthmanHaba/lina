<?php

use App\Enums\TeamRole;
use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;

// Setup mirrors AlertControllerTest: a team-owner helper, actingAs($user), {current_team} slug in URLs.
function widgetOwner(): array
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
    $user->update(['current_team_id' => $team->id]);

    return [$user, $team];
}

it('creates a timeseries widget with filters', function () {
    [$user, $team] = widgetOwner();
    $server = Server::factory()->for($team)->create();

    $this->actingAs($user)
        ->post(route('dashboard.widgets.store', $team->slug), [
            'type' => 'timeseries', 'title' => 'Volume', 'size' => 'full',
            'config' => ['server_ids' => [$server->id], 'sources' => ['nginx'], 'chart' => 'area', 'group_by' => 'source'],
        ])->assertRedirect();

    $widget = DashboardWidget::where('team_id', $team->id)->where('title', 'Volume')->firstOrFail();
    expect($widget->type)->toBe(WidgetType::Timeseries)
        ->and($widget->config['chart'])->toBe('area');
});

it('strips unknown config keys', function () {
    [$user, $team] = widgetOwner();

    $this->actingAs($user)
        ->post(route('dashboard.widgets.store', $team->slug), [
            'type' => 'stat', 'title' => 'Errors', 'size' => 'third',
            'config' => ['search' => 'error', 'evil' => 'x'],
        ])->assertRedirect();

    expect(DashboardWidget::where('title', 'Errors')->firstOrFail()->config)->not->toHaveKey('evil');
});

it('rejects logql on a log_stream widget', function () {
    [$user, $team] = widgetOwner();

    $this->actingAs($user)
        ->post(route('dashboard.widgets.store', $team->slug), [
            'type' => 'log_stream', 'title' => 'Logs', 'size' => 'full',
            'config' => ['logql' => '{server_id="1"}'],
        ])->assertSessionHasErrors('config.logql');
});

it('rejects server ids from another team', function () {
    [$user, $team] = widgetOwner();
    $foreign = Server::factory()->for(Team::factory())->create();

    $this->actingAs($user)
        ->post(route('dashboard.widgets.store', $team->slug), [
            'type' => 'timeseries', 'title' => 'Volume', 'size' => 'full',
            'config' => ['server_ids' => [$foreign->id]],
        ])->assertSessionHasErrors('config.server_ids.0');
});

it('rejects out-of-range window and limit', function () {
    [$user, $team] = widgetOwner();

    $this->actingAs($user)
        ->post(route('dashboard.widgets.store', $team->slug), [
            'type' => 'stat', 'title' => 'S', 'size' => 'third', 'config' => ['window_minutes' => 2],
        ])->assertSessionHasErrors('config.window_minutes');

    $this->actingAs($user)
        ->post(route('dashboard.widgets.store', $team->slug), [
            'type' => 'log_stream', 'title' => 'L', 'size' => 'full', 'config' => ['limit' => 500],
        ])->assertSessionHasErrors('config.limit');
});

it('updates title, size and config but never type', function () {
    [$user, $team] = widgetOwner();
    $widget = DashboardWidget::factory()->for($team)->create(['type' => WidgetType::Stat, 'config' => ['window_minutes' => 60]]);

    $this->actingAs($user)
        ->patch(route('dashboard.widgets.update', [$team->slug, $widget]), [
            'title' => 'Renamed', 'size' => 'half', 'type' => 'timeseries',
            'config' => ['window_minutes' => 120],
        ])->assertRedirect();

    $widget->refresh();
    expect($widget->title)->toBe('Renamed')
        ->and($widget->type)->toBe(WidgetType::Stat)
        ->and($widget->config['window_minutes'])->toBe(120);
});

it('404s on a foreign team widget', function () {
    [$user, $team] = widgetOwner();
    $widget = DashboardWidget::factory()->for(Team::factory())->create();

    $this->actingAs($user)
        ->patch(route('dashboard.widgets.update', [$team->slug, $widget]), ['title' => 'X', 'size' => 'half', 'config' => []])
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('dashboard.widgets.destroy', [$team->slug, $widget]))
        ->assertNotFound();
});

it('reorders widgets and rejects a partial id list', function () {
    [$user, $team] = widgetOwner();
    $a = DashboardWidget::factory()->for($team)->create(['position' => 0]);
    $b = DashboardWidget::factory()->for($team)->create(['position' => 1]);

    $this->actingAs($user)
        ->patch(route('dashboard.widgets.reorder', $team->slug), ['ids' => [$b->id, $a->id]])
        ->assertRedirect();
    expect($a->refresh()->position)->toBe(1)->and($b->refresh()->position)->toBe(0);

    $this->actingAs($user)
        ->patch(route('dashboard.widgets.reorder', $team->slug), ['ids' => [$a->id]])
        ->assertSessionHasErrors('ids');
});

it('deletes a widget', function () {
    [$user, $team] = widgetOwner();
    $widget = DashboardWidget::factory()->for($team)->create();

    $this->actingAs($user)
        ->delete(route('dashboard.widgets.destroy', [$team->slug, $widget]))
        ->assertRedirect();
    expect(DashboardWidget::find($widget->id))->toBeNull();
});

it('passes widgets and filter options to the dashboard page', function () {
    [$user, $team] = widgetOwner();
    DashboardWidget::seedDefaults($team);
    Server::factory()->for($team)->create(['name' => 'web-1']);

    $this->actingAs($user)
        ->get(route('dashboard', $team->slug))
        ->assertInertia(fn ($page) => $page->component('dashboard')
            ->has('widgets', 4)
            ->has('servers', 1)
            ->has('sources'));
});
