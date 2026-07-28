<?php

namespace App\Models;

use App\Services\AlloyConfigRenderer;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $token_hash
 * @property string|null $hostname
 * @property string|null $agent_version
 * @property int|null $retention_days
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_poll_at
 * @property Carbon|null $last_push_at
 * @property Carbon|null $config_synced_at
 * @property string|null $synced_config_hash
 * @property-read Team $team
 * @property-read Collection<int, LogSource> $logSources
 * @property-read Collection<int, UsageRecord> $usageRecords
 */
#[Fillable(['team_id', 'name', 'token_hash', 'hostname', 'agent_version', 'retention_days', 'last_seen_at', 'last_poll_at', 'last_push_at', 'config_synced_at', 'synced_config_hash'])]
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    /**
     * Regenerate the server token, returning the plaintext exactly once.
     */
    public function newToken(): string
    {
        $plain = Str::random(40);

        $this->forceFill(['token_hash' => hash('sha256', $plain)])->save();

        return $plain;
    }

    /**
     * Resolve a server from a plaintext bearer token.
     */
    public static function findByToken(?string $plainToken): ?self
    {
        if ($plainToken === null || $plainToken === '') {
            return null;
        }

        return static::where('token_hash', hash('sha256', $plainToken))->first();
    }

    /**
     * Derived agent status: pending until first contact, offline after 5 minutes of silence.
     */
    public function status(): string
    {
        return match (true) {
            $this->last_seen_at === null => 'pending',
            $this->last_seen_at->gt(now()->subMinutes(5)) => 'online',
            default => 'offline',
        };
    }

    /**
     * Bytes ingested today.
     */
    public function bytesToday(): int
    {
        return (int) $this->usageRecords()->whereDate('date', today())->sum('bytes');
    }

    /**
     * Config drift status derived from the last 304-confirmed hash.
     * Renders the current config — call from page views, not hot paths.
     */
    public function configStatus(): string
    {
        $current = sha1(app(AlloyConfigRenderer::class)->render($this));

        return match (true) {
            $this->synced_config_hash === $current => 'synced',
            (bool) $this->last_poll_at?->gt(now()->subMinutes(5)) => 'syncing',
            default => 'unknown',
        };
    }

    /**
     * Daily ingest bytes for the trailing window, zero-filled, oldest first.
     *
     * @return array<int, array{date: string, bytes: int}>
     */
    public function usageSeries(int $days = 14): array
    {
        $bytesByDate = $this->usageRecords()
            ->whereDate('date', '>=', today()->subDays($days - 1))
            ->get()
            ->mapWithKeys(fn (UsageRecord $record) => [$record->date->toDateString() => $record->bytes]);

        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo) => [
                'date' => today()->subDays($daysAgo)->toDateString(),
                'bytes' => (int) ($bytesByDate[today()->subDays($daysAgo)->toDateString()] ?? 0),
            ])
            ->all();
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<LogSource, $this> */
    public function logSources(): HasMany
    {
        return $this->hasMany(LogSource::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'last_seen_at' => 'datetime',
            'last_poll_at' => 'datetime',
            'last_push_at' => 'datetime',
            'config_synced_at' => 'datetime',
        ];
    }
}
