<?php

namespace App\Services\Notifications;

use App\Models\Notification;

/** One page of the notification centre, and where the next one starts. */
final class NotificationPage
{
    /** @param list<Notification> $items */
    public function __construct(
        public readonly array $items,

        /** Null when this is the end of the history. */
        public readonly ?int $nextCursor,
    ) {}
}
