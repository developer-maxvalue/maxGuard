<?php

namespace App\Http\Requests;

use App\Models\AiSetting;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateAiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'provider' => ['required', 'in:gemini,ollama,openai_compatible'],
            'base_url' => ['required', 'url', 'max:2048', 'starts_with:http://,https://'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_api_key' => ['nullable', 'boolean'],
            'model' => ['required', 'string', 'max:255'],
            'output_language' => ['required', 'string', 'max:80'],
            'max_pages_per_scan' => ['required', 'integer', 'min:0', 'max:100000'],
            'min_confidence' => ['required', 'integer', 'min:0', 'max:100'],
            'max_input_chars' => ['required', 'integer', 'min:1000', 'max:1000000'],
            'max_output_tokens' => ['required', 'integer', 'min:100', 'max:100000'],
            'connect_timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'test_connection' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->boolean('enabled') || $this->input('provider') !== 'gemini') {
                return;
            }

            $hasStoredKey = filled(AiSetting::query()->latest('id')->value('api_key'));
            if ($this->boolean('clear_api_key') || (! $hasStoredKey && blank($this->input('api_key')))) {
                $validator->errors()->add('api_key', 'Gemini yêu cầu API key khi bật phân tích AI.');
            }
        });
    }
}
