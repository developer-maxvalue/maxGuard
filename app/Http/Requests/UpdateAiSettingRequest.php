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
            'review_enabled' => ['nullable', 'boolean'],
            'provider' => ['required', 'in:gemini,anthropic,ollama,openai_compatible'],
            'base_url' => ['required', 'url', 'max:2048', 'starts_with:http://,https://'],
            'api_key' => ['nullable', 'string', 'max:4096'],
            'clear_api_key' => ['nullable', 'boolean'],
            'model' => ['required', 'string', 'max:255'],
            'review_provider' => ['required', 'in:anthropic'],
            'review_base_url' => ['required', 'url', 'max:2048', 'starts_with:http://,https://'],
            'review_api_key' => ['nullable', 'string', 'max:4096'],
            'clear_review_api_key' => ['nullable', 'boolean'],
            'review_model' => ['required', 'string', 'max:255'],
            'output_language' => ['required', 'string', 'max:80'],
            'max_pages_per_scan' => ['required', 'integer', 'min:0', 'max:100000'],
            'min_confidence' => ['required', 'integer', 'min:0', 'max:100'],
            'max_input_chars' => ['required', 'integer', 'min:1000', 'max:1000000'],
            'max_output_tokens' => ['required', 'integer', 'min:100', 'max:100000'],
            'connect_timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'timeout_seconds' => ['required', 'integer', 'min:5', 'max:3600'],
            'test_connection' => ['nullable', 'in:page,review'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->boolean('enabled') && in_array($this->input('provider'), ['gemini', 'anthropic'], true)) {
                $hasStoredKey = filled(AiSetting::query()->latest('id')->value('api_key'));
                if ($this->boolean('clear_api_key') || (! $hasStoredKey && blank($this->input('api_key')))) {
                    $provider = $this->input('provider') === 'anthropic' ? 'Claude/Anthropic' : 'Gemini';
                    $validator->errors()->add('api_key', $provider.' yêu cầu API key khi bật AI kiểm tra từng URL.');
                }
            }

            if ($this->boolean('review_enabled')) {
                $hasStoredReviewKey = filled(AiSetting::query()->latest('id')->value('review_api_key'));
                if ($this->boolean('clear_review_api_key') || (! $hasStoredReviewKey && blank($this->input('review_api_key')))) {
                    $validator->errors()->add('review_api_key', 'Claude/Anthropic yêu cầu API key khi bật đánh giá realtime.');
                }
            }
        });
    }
}
