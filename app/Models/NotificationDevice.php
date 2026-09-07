<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A handset a customer has asked to be notified on.
 *
 * The token is the address of somebody's phone. It is hidden so that no
 * resource, no `toArray()` and no accidental dump can publish it, and
 * nothing in FynnEdge logs it.
 */
class NotificationDevice extends Model
{
    protected $fillable = ['token', 'platform', 'active', 'last_seen_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
