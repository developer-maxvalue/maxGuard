<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class Finding extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'website_id',
        'scan_id',
        'page_id',
        'assigned_to',
        'fingerprint',
        'rule_key',
        'category',
        'severity',
        'confidence',
        'status',
        'title',
        'summary',
        'policy_reference',
        'revenue_impact',
        'signals',
        'remediation',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'signals' => 'array',
        'remediation' => 'array',
        'revenue_impact' => 'decimal:2',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Finding $finding): void {
            $finding->public_id ??= 'MG-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function evidenceItems(): HasMany
    {
        return $this->hasMany(EvidenceItem::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'investigating', 'remediating']);
    }
}

