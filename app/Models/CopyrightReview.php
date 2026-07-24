<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CopyrightReview extends Model
{
    protected $fillable = ['website_id', 'page_id', 'reviewed_by', 'status', 'google_query', 'matched_url', 'notes', 'reviewed_at'];
    protected $casts = ['reviewed_at' => 'datetime'];

    /** Return the page inspected by the human reviewer. */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** Return the website that owns the reviewed page. */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
