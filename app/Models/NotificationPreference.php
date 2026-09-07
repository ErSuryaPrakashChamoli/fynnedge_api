<?php

namespace App\Models;

use App\Domain\Notifications\NotificationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's choices about how FynnEdge reaches them. */
class NotificationPreference extends Model
{
    protected $fillable = ['push_enabled', 'muted_categories'];

    protected function casts(): array
    {
        return ['push_enabled' => 'boolean', 'muted_categories' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<NotificationCategory> */
    public function mutedCategories(): array
    {
        return array_values(array_filter(array_map(
            fn ($c) => NotificationCategory::tryFrom((string) $c),
            $this->muted_categories ?? [],
        )));
    }
}
