<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() || config('maxguard.route_middleware') === [];
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:open,investigating,remediating,resolved'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}

