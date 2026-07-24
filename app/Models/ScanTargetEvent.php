<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observable step in a URL scan, for example crawl, local rules,
 * Sightengine or Gemini. Secrets and full page content must never be stored.
 */
final class ScanTargetEvent extends Model
{
    protected $fillable = [
        'scan_id', 'scan_target_id', 'stage', 'status', 'duration_ms',
        'service', 'http_status', 'request_id', 'message', 'context',
        'started_at', 'finished_at',
    ];

    protected $casts = [
        'context' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Return the URL target that owns this event. */
    public function target(): BelongsTo
    {
        return $this->belongsTo(ScanTarget::class, 'scan_target_id');
    }

    /** Return the parent scan for efficient scan-level filtering. */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
