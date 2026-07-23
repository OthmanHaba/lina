<?php

namespace App\Models;

use App\Enums\LogSourceType;
use Database\Factories\LogSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property LogSourceType $type
 * @property string $name
 * @property array<string, mixed>|null $config
 * @property bool $enabled
 * @property-read Server $server
 */
#[Fillable(['server_id', 'type', 'name', 'config', 'enabled'])]
class LogSource extends Model
{
    /** @use HasFactory<LogSourceFactory> */
    use HasFactory;

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LogSourceType::class,
            'config' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
