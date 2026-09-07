<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One catalogue change, recorded.
 *
 * Append-only, and the model enforces it: an audit trail that can be edited
 * answers no question worth asking. There is no updated_at because nothing
 * is ever updated.
 */
class CatalogueAuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id', 'actor_label', 'entity_type', 'entity_id',
        'action', 'before', 'after',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Catalogue audit records cannot be changed.');
        });

        static::deleting(function () {
            throw new RuntimeException('Catalogue audit records cannot be deleted.');
        });
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
