<?php

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationDecision;
use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a notification row comes into existence.
 *
 * Everything that wants to tell a customer something goes through here with
 * a decision the policy made. Nothing else inserts into the table, so
 * "could two of these exist?" has exactly one place to be answered.
 *
 * The answer is the unique index on (user_id, idempotency_key), reached
 * through insertOrIgnore. That matters more than it looks:
 *
 * A read-then-write — "does one exist? no, insert" — is a race with a
 * window between the two statements, and provider redelivery, queue retries
 * and a customer double-tapping all aim straight at it. Two workers both
 * read nothing and both insert. The database is the only thing that can
 * decide this, so it does, and the insert that loses simply does nothing
 * rather than raising an error that would poison a surrounding transaction.
 *
 * Recording happens inside whatever transaction the caller is already in.
 * That is deliberate: a provider event that is rolled back must not leave a
 * customer holding a notification about something that did not happen.
 */
class NotificationRecorder
{
    /**
     * Record a decision, or recognise that it is already recorded.
     *
     * Returns the notification either way. `wasCreated` is what tells a
     * caller whether this delivery is the first, and therefore whether
     * anything should be pushed.
     */
    public function record(User $user, ?NotificationDecision $decision): RecordedNotification
    {
        if ($decision === null) {
            // The ordinary answer. Most of what happens inside FynnEdge is
            // not something to tell a customer.
            return RecordedNotification::none();
        }

        $now = now();

        $inserted = DB::table('notifications')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'type' => $decision->type->value,
            'category' => $decision->category()->value,
            'title' => $decision->title,
            'body' => $decision->body,
            'deep_link' => $decision->deepLink,
            'idempotency_key' => $decision->idempotencyKey,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var Notification $notification */
        $notification = Notification::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $decision->idempotencyKey)
            ->firstOrFail();

        return new RecordedNotification($notification, $inserted === 1);
    }

    /**
     * Record and, if this is the first time, queue a push attempt.
     *
     * The two are separate calls on purpose. A caller inside a transaction
     * that might roll back records only; queueing a delivery for a row that
     * may not survive would be queueing a message about nothing.
     */
    public function recordAndDeliver(User $user, ?NotificationDecision $decision): RecordedNotification
    {
        $recorded = $this->record($user, $decision);

        if ($recorded->wasCreated && $recorded->notification !== null) {
            // afterCommit, because a caller may be inside a transaction
            // that has not committed. A worker picking the job up first
            // would look for a row that is not there yet.
            DeliverNotification::dispatch($recorded->notification->getKey())
                ->afterCommit();
        }

        return $recorded;
    }
}
