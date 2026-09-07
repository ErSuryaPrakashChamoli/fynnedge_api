<?php

namespace App\Models;

use App\Domain\Notifications\DeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One attempt to push one notification to one device. */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'notification_device_id',
        'channel',
        'provider',
        'is_simulated',
        'status',
        'reason',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return ['is_simulated' => 'boolean', 'attempted_at' => 'datetime'];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(NotificationDevice::class, 'notification_device_id');
    }

    public function status(): DeliveryStatus
    {
        return DeliveryStatus::from($this->status);
    }
}
