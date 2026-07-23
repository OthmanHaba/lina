<?php

namespace App\Http\Requests\Servers;

use App\Enums\LogSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLogSourceRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = LogSourceType::tryFrom((string) $this->input('type'));

        // Config rules always apply on store; on update only when the request
        // includes a config key (partial updates keep the stored config).
        $enforceConfig = $this->route('source') === null || $this->has('config');

        return [
            'type' => ['required', Rule::enum(LogSourceType::class)],
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['sometimes', 'array'],
            ...($enforceConfig ? match ($type) {
                LogSourceType::Systemd => [
                    'config.units' => ['sometimes', 'array'],
                    'config.units.*' => ['string', 'max:255'],
                ],
                LogSourceType::Docker => [
                    'config.containers' => ['sometimes', 'array'],
                    'config.containers.*' => ['string', 'max:255'],
                ],
                LogSourceType::Nginx => [
                    'config.access_log' => ['sometimes', 'string', 'max:1024'],
                    'config.error_log' => ['sometimes', 'string', 'max:1024'],
                ],
                LogSourceType::File => [
                    'config.paths' => ['required', 'array', 'min:1'],
                    'config.paths.*' => ['string', 'max:1024'],
                ],
                default => [],
            } : []),
        ];
    }

    /**
     * Normalized config with per-type defaults applied.
     *
     * @return array<string, mixed>
     */
    public function sourceConfig(): array
    {
        $config = $this->validated('config', []);

        if ($this->validated('type') === LogSourceType::Nginx->value) {
            $config += ['access_log' => '/var/log/nginx/access.log', 'error_log' => '/var/log/nginx/error.log'];
        }

        return $config;
    }
}
