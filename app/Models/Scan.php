<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Scan extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'website_id',
        'requested_by',
        'type',
        'status',
        'progress',
        'max_urls',
        'use_ai',
        'force_rescan',
        'pages_discovered',
        'pages_scanned',
        'pages_skipped_unchanged',
        'ai_pages_analyzed',
        'ai_findings_count',
        'findings_count',
        'score',
        'ruleset_version',
        'started_at',
        'finished_at',
        'error_message',
        'current_url',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'use_ai' => 'boolean',
        'force_rescan' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'last_scan_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(EvidenceItem::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ScanTarget::class);
    }
}
