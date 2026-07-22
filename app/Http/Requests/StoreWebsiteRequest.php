<?php

namespace App\Http\Requests;

use App\Models\Website;
use App\Services\SafeUrlValidator;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWebsiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() || config('maxguard.route_middleware') === [];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'start_url' => ['required', 'url', 'max:2048', 'starts_with:http://,https://'],
            'expected_monthly_revenue' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('start_url')) {
                return;
            }

            try {
                app(SafeUrlValidator::class)->publicIps((string) $this->input('start_url'));
                $domain = strtolower((string) parse_url((string) $this->input('start_url'), PHP_URL_HOST));
                if ($domain !== '' && Website::query()->where('domain', $domain)->exists()) {
                    $validator->errors()->add('start_url', 'This domain is already registered.');
                }
            } catch (\Throwable $exception) {
                $validator->errors()->add('start_url', $exception->getMessage());
            }
        });
    }
}
