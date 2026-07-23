<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => $this->faker->unique()->domainWord().'-server',
            'token_hash' => hash('sha256', Str::random(40)),
            'hostname' => null,
            'agent_version' => null,
            'last_seen_at' => null,
        ];
    }

    public function online(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subSeconds(30), 'hostname' => $this->faker->domainName()]);
    }

    public function offline(): static
    {
        return $this->state(fn () => ['last_seen_at' => now()->subHour(), 'hostname' => $this->faker->domainName()]);
    }
}
