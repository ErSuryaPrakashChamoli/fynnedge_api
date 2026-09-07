<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;

/**
 * Reading the notification centre.
 *
 * Separate from NotificationRecorder on purpose: one class writes and one
 * reads, and neither can do the other's job. Nothing here can create a
 * notification.
 *
 * Every query is a customer's own relation plus an indexed column. The
 * table is designed for years of history, so nothing loads all of it — not
 * the list, and above all not the unread count, which is what a badge asks
 * for on every visit to Home.
 */
class NotificationCentre
{
    public const DEFAULT_PAGE = 20;

    public const MAX_PAGE = 50;

    /**
     * One page, newest first.
     *
     * Keyed on id rather than an offset. Ids descend with time, so "older
     * than the last one I hold" is a single indexed comparison — and, more
     * usefully, a notification arriving mid-scroll cannot shift a page
     * boundary and make the customer see something twice or miss it.
     */
    public function page(User $user, int $limit = self::DEFAULT_PAGE, ?int $before = null): NotificationPage
    {
        $limit = max(1, min($limit, self::MAX_PAGE));

        $query = $user->notifications()->orderByDesc('id');

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        // One more than asked for, so "is there another page?" is answered
        // without a second count over the history.
        $rows = $query->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $items = $rows->take($limit);

        return new NotificationPage(
            $items->values()->all(),
            $hasMore ? (int) $items->last()->id : null,
        );
    }

    /** A count on an index, never a page of rows counted in PHP. */
    public function unreadCount(User $user): int
    {
        return $user->notifications()->whereNull('read_at')->count();
    }

    /**
     * Mark one read.
     *
     * The first read_at stands. Reading something a second time does not
     * move the moment they first saw it, and a client retrying a request
     * cannot rewrite it either.
     */
    public function markRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification->fresh();
    }

    /**
     * Mark everything read, in one statement.
     *
     * Issued through the customer's own relation, so it cannot reach a row
     * that is not theirs however it is called. Returns how many actually
     * changed, which is zero the second time — the honest answer to a
     * repeated request, and not an error.
     */
    public function markAllRead(User $user): int
    {
        return $user->notifications()
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);
    }
}
