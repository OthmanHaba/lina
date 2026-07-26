<?php

namespace App\Models;

use App\Enums\AlertState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $alert_id
 * @property AlertState $state
 * @property int $measured_value
 * @property-read Alert $alert
 */
#[Fillable(['alert_id', 'state', 'measured_value'])]
class AlertEvent extends Model
{
    /** @return BelongsTo<Alert, $this> */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => AlertState::class,
            'measured_value' => 'integer',
        ];
    }
}
