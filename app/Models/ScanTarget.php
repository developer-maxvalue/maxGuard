<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ScanTarget extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REUSED = 'reused';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scan_id',
        'page_id',
        'position',
        'batch_number',
        'url',
        'url_hash',
        'status',
        'claim_token',
        'attempts',
        'analysis_reused',
        'ai_attempted',
        'ai_analyzed',
        'findings_count',
        'ai_findings_count',
        'ai_input_tokens',
        'ai_output_tokens',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'analysis_reused' => 'boolean',
        'ai_attempted' => 'boolean',
        'ai_analyzed' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
