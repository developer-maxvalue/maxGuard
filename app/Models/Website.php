<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class Website extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'domain',
        'start_url',
        'status',
        'overall_score',
        'expected_monthly_revenue',
        'pages_count',
        'last_discovered_pages',
        'last_scanned_pages',
        'last_scan_partial',
        'open_findings_count',
        'last_scanned_at',
        'next_scan_at',
        'settings',
    ];

    protected $casts = [
        'expected_monthly_revenue' => 'decimal:2',
        'settings' => 'array',
        'last_scan_partial' => 'boolean',
        'last_scanned_at' => 'datetime',
        'next_scan_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function ga4Connection(): HasOne
    {
        return $this->hasOne(WebsiteGa4Connection::class);
    }

    public function copyrightReviews(): HasMany
    {
        return $this->hasMany(CopyrightReview::class);
    }

    public function scopeDueForScan(Builder $query): Builder
    {
        return $query
            ->whereNotIn('status', ['disabled', 'scanning'])
            ->where(function (Builder $query): void {
                $query->whereNull('next_scan_at')->orWhere('next_scan_at', '<=', now());
            });
    }

    public function scopeAccessibleBy(Builder $query, ?int $userId): Builder
    {
        if ($userId !== null && auth()->id() === $userId && auth()->user()?->is_admin) {
            return $query;
        }

        return $userId === null ? $query : $query->where('user_id', $userId);
    }

    public static function statusFromScore(int $score): string
    {
        return match (true) {
            $score < 70 => 'critical',
            $score < 80 => 'high',
            $score < 90 => 'review',
            default => 'healthy',
        };
    }
}
