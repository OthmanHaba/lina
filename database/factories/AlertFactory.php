<?php

namespace Database\Factories;

use App\Enums\AlertState;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->unique()->word().'-alert',
            'type' => AlertType::Threshold,
            'config' => ['servers' => [], 'sources' => [], 'search' => 'error', 'threshold' => 5, 'window_minutes' => 5],
            'state' => AlertState::Ok,
            'enabled' => true,
        ];
    }

    public function missingLogs(): static
    {
        return $this->state(fn () => [
            'type' => AlertType::MissingLogs,
            'config' => ['servers' => [], 'sources' => [], 'search' => null, 'threshold' => null, 'window_minutes' => 10],
        ]);
    }

    public function firing(): static
    {
        return $this->state(fn () => ['state' => AlertState::Firing, 'last_state_change_at' => now()->subMinutes(5)]);
    }
}
