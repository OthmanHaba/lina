<?php

namespace App\Models;

use App\Enums\WidgetSize;
use App\Enums\WidgetType;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property WidgetType $type
 * @property string $title
 * @property WidgetSize $size
 * @property int $position
 * @property array<string, mixed> $config
 * @property-read Team $team
 */
#[Fillable(['team_id', 'type', 'title', 'size', 'position', 'config'])]
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The default dashboard every team starts with.
     */
    public static function seedDefaults(Team $team): void
    {
        $defaults = [
            ['type' => WidgetType::Timeseries, 'title' => 'Log volume', 'size' => WidgetSize::Full, 'position' => 0, 'config' => ['chart' => 'bar', 'group_by' => 'server']],
            ['type' => WidgetType::Stat, 'title' => 'Errors (last hour)', 'size' => WidgetSize::Third, 'position' => 1, 'config' => ['search' => 'error', 'window_minutes' => 60]],
            ['type' => WidgetType::ServerHealth, 'title' => 'Servers', 'size' => WidgetSize::Third, 'position' => 2, 'config' => []],
            ['type' => WidgetType::LogStream, 'title' => 'Latest logs', 'size' => WidgetSize::Full, 'position' => 3, 'config' => ['limit' => 25]],
        ];

        foreach ($defaults as $attributes) {
            $team->widgets()->create($attributes);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WidgetType::class,
            'size' => WidgetSize::class,
            'config' => 'array',
        ];
    }
}
