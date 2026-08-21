<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StartScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() || config('maxguard.route_middleware') === [];
    }

    public function rules(): array
    {
        $safetyLimit = max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000));

        return [
            'site' => ['required', 'string', 'max:255'],
            'scan_type' => ['required', 'in:full,priority,copyright,ads,privacy'],
            'max_urls' => ['nullable', 'integer', 'min:1', 'max:'.$safetyLimit],
            'scan_all_site' => ['nullable', 'boolean'],
            'use_ai' => ['nullable', 'boolean'],
            'force_rescan' => ['nullable', 'boolean'],
        ];
    }
}
