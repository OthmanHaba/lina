<?php

namespace App\Models;

use App\Enums\AlertState;
use App\Enums\AlertType;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property AlertType $type
 * @property array<string, mixed> $config
 * @property AlertState $state
 * @property bool $enabled
 * @property Carbon|null $last_evaluated_at
 * @property Carbon|null $last_state_change_at
 * @property-read Team $team
 * @property-read Collection<int, NotificationChannel> $channels
 * @property-read Collection<int, AlertEvent> $events
 */
#[Fillable(['team_id', 'name', 'type', 'config', 'state', 'enabled', 'last_evaluated_at', 'last_state_change_at'])]
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsToMany<NotificationChannel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class, 'alert_channel');
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'state' => AlertState::class,
            'config' => 'array',
            'enabled' => 'boolean',
            'last_evaluated_at' => 'datetime',
            'last_state_change_at' => 'datetime',
        ];
    }
}
