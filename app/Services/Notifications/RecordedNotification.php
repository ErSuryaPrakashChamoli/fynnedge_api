<?php

namespace App\Services\Notifications;

use App\Models\Notification;

/**
 * What came of recording a decision.
 *
 * Three outcomes, and they are genuinely different: nothing was worth
 * telling; a notification was created; a notification already existed
 * because this event has been seen before. The last two both return a
 * notification, and only the middle one should cause a push.
 */
final class RecordedNotification
{
    public function __construct(
        public readonly ?Notification $notification,
        public readonly bool $wasCreated,
    ) {}

    public static function none(): self
    {
        return new self(null, false);
    }

    /** Whether the policy decided this was worth saying at all. */
    public function wasDecided(): bool
    {
        return $this->notification !== null;
    }

    /** Whether this event had already produced a notification. */
    public function wasDuplicate(): bool
    {
        return $this->notification !== null && ! $this->wasCreated;
    }
}
