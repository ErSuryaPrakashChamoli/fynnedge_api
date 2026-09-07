<?php

namespace App\Models;

use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing FynnEdge has told one customer.
 *
 * The row is the message, not the thing the message is about. Whatever it
 * refers to — an application, a document, a score — is reached through the
 * deep link and read from its own table, so nothing here is a second copy
 * of anything sensitive.
 */
class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'category',
        'title',
        'body',
        'deep_link',
        'idempotency_key',
        'read_at',
    ];

    /**
     * The key is FynnEdge's own bookkeeping and says which internal event
     * produced this. A customer has no use for it and it describes the
     * shape of the system, so it never leaves the server.
     */
    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(NotificationDelivery::class);
    }

    public function type(): NotificationType
    {
        return NotificationType::from($this->type);
    }

    public function category(): NotificationCategory
    {
        return NotificationCategory::from($this->category);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
