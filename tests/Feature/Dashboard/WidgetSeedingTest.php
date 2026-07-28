<?php

use App\Actions\Teams\CreateTeam;
use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use App\Models\Team;
use App\Models\User;

it('seeds four default widgets when a team is created', function () {
    $user = User::factory()->create();

    $team = app(CreateTeam::class)->handle($user, 'Acme');

    expect($team->widgets()->orderBy('position')->pluck('type')->all())->toBe([
        WidgetType::Timeseries, WidgetType::Stat, WidgetType::ServerHealth, WidgetType::LogStream,
    ]);
});

it('seedDefaults creates the documented defaults', function () {
    $team = Team::factory()->create();

    DashboardWidget::seedDefaults($team);

    $widgets = $team->widgets()->orderBy('position')->get();
    expect($widgets)->toHaveCount(4)
        ->and($widgets[0]->size)->toBe(WidgetSize::Full)
        ->and($widgets[0]->config['group_by'])->toBe('server')
        ->and($widgets[1]->config['search'])->toBe('error')
        ->and($widgets[3]->config['limit'])->toBe(25);
});
