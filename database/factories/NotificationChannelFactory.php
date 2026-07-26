<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\NotificationChannel;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationChannel>
 */
class NotificationChannelFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => ChannelType::Email,
            'name' => $this->faker->unique()->word().'-channel',
            'config' => ['address' => $this->faker->safeEmail()],
            'enabled' => true,
            'failure_count' => 0,
        ];
    }

    public function webhook(?string $url = null): static
    {
        return $this->state(fn () => [
            'type' => ChannelType::Webhook,
            'config' => ['url' => $url ?? 'https://hooks.example.test/lina'],
        ]);
    }

    public function telegram(string $chatId = '123456789'): static
    {
        return $this->state(fn () => [
            'type' => ChannelType::Telegram,
            'config' => ['chat_id' => $chatId],
        ]);
    }
}
