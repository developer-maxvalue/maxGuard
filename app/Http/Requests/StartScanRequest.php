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
        return [
            'site' => ['required', 'string', 'max:255'],
            'scan_type' => ['required', 'in:full,priority,copyright,ads,privacy'],
        ];
    }
}

