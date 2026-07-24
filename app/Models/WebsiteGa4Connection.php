<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WebsiteGa4Connection extends Model
{
    protected $fillable = ['website_id', 'property_id', 'access_token', 'refresh_token', 'token_expires_at', 'last_synced_at', 'last_error'];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    /** Return the website whose encrypted OAuth credentials are stored here. */
    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
