<?php

namespace Database\Factories;

use App\Enums\LogSourceType;
use App\Models\LogSource;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogSource>
 */
class LogSourceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'type' => LogSourceType::Systemd,
            'name' => $this->faker->unique()->word(),
            'config' => [],
            'enabled' => true,
        ];
    }

    public function file(array $paths = ['/var/log/app/*.log']): static
    {
        return $this->state(fn () => ['type' => LogSourceType::File, 'config' => ['paths' => $paths]]);
    }

    public function nginx(): static
    {
        return $this->state(fn () => ['type' => LogSourceType::Nginx, 'config' => ['access_log' => '/var/log/nginx/access.log', 'error_log' => '/var/log/nginx/error.log']]);
    }

    public function docker(array $containers = []): static
    {
        return $this->state(fn () => ['type' => LogSourceType::Docker, 'config' => ['containers' => $containers]]);
    }

    public function systemd(array $units = []): static
    {
        return $this->state(fn () => ['type' => LogSourceType::Systemd, 'config' => ['units' => $units]]);
    }
}
