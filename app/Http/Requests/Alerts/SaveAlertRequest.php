<?php

namespace App\Http\Requests\Alerts;

use App\Enums\AlertType;
use App\Models\NotificationChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAlertRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(AlertType::class)],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['required', 'array'],
            'config.window_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'config.threshold' => ['required_if:type,threshold', 'nullable', 'integer', 'min:1'],
            'config.search' => ['sometimes', 'nullable', 'string', 'max:500'],
            'config.servers' => ['sometimes', 'array'],
            'config.servers.*' => ['integer'],
            'config.sources' => ['sometimes', 'array'],
            'config.sources.*' => ['string', 'max:100'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => [
                'integer',
                Rule::exists(NotificationChannel::class, 'id')
                    ->where('team_id', $this->user()->current_team_id),
            ],
        ];
    }

    /**
     * Canonical config: all keys present, type-irrelevant keys nulled.
     *
     * @return array<string, mixed>
     */
    public function alertConfig(): array
    {
        $config = $this->validated('config');
        $isThreshold = $this->validated('type') === AlertType::Threshold->value;

        return [
            'servers' => array_values(array_map(intval(...), $config['servers'] ?? [])),
            'sources' => array_values($config['sources'] ?? []),
            'search' => $isThreshold ? ($config['search'] ?? null) : null,
            'threshold' => $isThreshold ? (int) $config['threshold'] : null,
            'window_minutes' => (int) $config['window_minutes'],
        ];
    }
}
