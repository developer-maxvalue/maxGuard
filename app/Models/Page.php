<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'last_scan_id',
        'url',
        'url_hash',
        'canonical_url',
        'status_code',
        'title',
        'language',
        'content_hash',
        'word_count',
        'ad_count',
        'snapshot_path',
        'last_scanned_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_scanned_at' => 'datetime',
    ];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function lastScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'last_scan_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }
}

