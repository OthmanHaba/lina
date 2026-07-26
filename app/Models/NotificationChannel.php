<?php

namespace App\Models;

use App\Enums\ChannelType;
use Database\Factories\NotificationChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property ChannelType $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property bool $enabled
 * @property int $failure_count
 * @property-read Team $team
 */
#[Fillable(['team_id', 'type', 'name', 'config', 'enabled', 'failure_count'])]
class NotificationChannel extends Model
{
    /** @use HasFactory<NotificationChannelFactory> */
    use HasFactory;

    /**
     * Consecutive delivery failures before a channel is auto-disabled.
     */
    public const int MAX_FAILURES = 10;

    /**
     * Record a delivery failure; disable the channel after MAX_FAILURES in a row.
     */
    public function recordFailure(): void
    {
        $this->increment('failure_count');

        if ($this->failure_count >= self::MAX_FAILURES) {
            $this->forceFill(['enabled' => false])->save();
        }
    }

    /**
     * Record a delivery success, resetting the failure streak.
     */
    public function recordSuccess(): void
    {
        if ($this->failure_count > 0) {
            $this->forceFill(['failure_count' => 0])->save();
        }
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ChannelType::class,
            'config' => 'array',
            'enabled' => 'boolean',
            'failure_count' => 'integer',
        ];
    }
}
