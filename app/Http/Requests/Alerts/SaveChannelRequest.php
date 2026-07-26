<?php

namespace App\Http\Requests\Alerts;

use App\Enums\ChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveChannelRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = ChannelType::tryFrom((string) $this->input('type'));

        return [
            'type' => ['required', Rule::enum(ChannelType::class)],
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['sometimes', 'boolean'],
            'config' => ['required', 'array'],
            ...match ($type) {
                ChannelType::Email => ['config.address' => ['required', 'email', 'max:255']],
                ChannelType::Webhook => ['config.url' => ['required', 'url:http,https', 'max:1024']],
                ChannelType::Telegram => ['config.chat_id' => ['required', 'string', 'max:64']],
                default => [],
            },
        ];
    }
}
