<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiSetting extends Model
{
    protected $fillable = [
        'enabled',
        'provider',
        'base_url',
        'api_key',
        'model',
        'output_language',
        'max_pages_per_scan',
        'min_confidence',
        'max_input_chars',
        'max_output_tokens',
        'connect_timeout_seconds',
        'timeout_seconds',
        'updated_by',
    ];

    protected $hidden = ['api_key'];

    protected $casts = [
        'enabled' => 'boolean',
        'api_key' => 'encrypted',
        'max_pages_per_scan' => 'integer',
        'min_confidence' => 'integer',
        'max_input_chars' => 'integer',
        'max_output_tokens' => 'integer',
        'connect_timeout_seconds' => 'integer',
        'timeout_seconds' => 'integer',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
