<?php

namespace Database\Factories;

use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use App\Models\DashboardWidget;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => WidgetType::Timeseries,
            'title' => $this->faker->words(2, true),
            'size' => WidgetSize::Half,
            'position' => 0,
            'config' => [],
        ];
    }
}
