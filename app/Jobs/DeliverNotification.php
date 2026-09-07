<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\Notifications\PushDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Attempt to push one notification, away from the customer's request.
 *
 * Carries an id and nothing else. A job payload sits in a queue store,
 * which is a second place a title and body would live, and there is no
 * reason for them to be there when the row already exists.
 *
 * Safe to run more than once: PushDeliveryService is keyed on
 * (notification, device), so a retry re-attempts the same delivery row
 * rather than creating a second one — and it cannot create a notification,
 * because only NotificationRecorder can.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $notificationId) {}

    public function handle(PushDeliveryService $deliveries): void
    {
        $notification = Notification::find($this->notificationId);

        if ($notification === null) {
            // Deleted, or its owner was. Nothing to deliver and nothing
            // that needs saying about it.
            return;
        }

        $deliveries->deliver($notification);
    }
}
