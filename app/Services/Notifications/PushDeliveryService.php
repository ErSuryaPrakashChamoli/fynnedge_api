<?php

namespace App\Services\Notifications;

use App\Domain\Notifications\DeliveryAttempt;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\NotificationDeliveryProvider;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDevice;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trying to reach a customer's phone, and being honest about whether it
 * worked.
 *
 *     NOTIFICATION CREATED  ≠  PUSH DELIVERED
 *
 * Nothing in this class can remove or alter a notification. A push that
 * fails, a provider that is not configured, a customer with no device and a
 * customer who turned push off all end the same way for the notification
 * itself: it is in the notification centre, unchanged, waiting to be read.
 *
 * A delivery row is written for every attempt including the ones that never
 * left, because "we did not try, and here is why" is the operationally
 * useful thing to know and an absent row says nothing at all.
 */
class PushDeliveryService
{
    /**
     * Attempt to push one notification to every device the customer has.
     *
     * Idempotent by (notification, device): a retried job updates the row
     * it wrote before rather than adding another, so this table can never
     * claim a customer was pushed twice for one notification.
     *
     * @return list<NotificationDelivery>
     */
    public function deliver(Notification $notification): array
    {
        $provider = $this->provider();
        $user = $notification->user;

        if ($user === null) {
            return [];
        }

        $preference = $this->preferenceFor($notification);

        if ($preference !== null) {
            return [$this->write($notification, null, DeliveryStatus::Unavailable, $preference)];
        }

        if (! $provider->isAvailable()) {
            /*
             * NO PUSH PROVIDER IS CONNECTED, which is the ordinary state of
             * this project. Recorded as unavailable — never as sent, and
             * never as failed, because nothing was attempted and nothing
             * went wrong.
             */
            return [$this->write($notification, null, DeliveryStatus::Unavailable, 'no_push_provider')];
        }

        $devices = NotificationDevice::query()
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->get();

        if ($devices->isEmpty()) {
            return [$this->write($notification, null, DeliveryStatus::NoDevice, 'no_registered_device')];
        }

        $written = [];

        foreach ($devices as $device) {
            try {
                $attempt = $provider->push($notification, $device);
            } catch (Throwable $e) {
                /*
                 * A provider that threw is a failure to deliver and nothing
                 * more. The exception's message is not recorded: it comes
                 * from outside FynnEdge and could carry anything.
                 */
                Log::warning('notification_push_failed', [
                    'notification_id' => $notification->getKey(),
                    'provider' => $provider->name(),
                    'exception' => $e::class,
                ]);

                $attempt = DeliveryAttempt::failed('provider_error');
            }

            $written[] = $this->write(
                $notification,
                $device,
                $this->honestStatus($attempt, $provider),
                $attempt->reason,
                $provider->name(),
                $provider->isSimulated(),
            );
        }

        return $written;
    }

    /**
     * The status FynnEdge is entitled to record.
     *
     * A simulated provider cannot report Sent. It is the one place a
     * fixture could quietly become a claim that a customer's phone was
     * reached, so the claim is downgraded here rather than trusted — the
     * provider says it is simulated, and simulated is what gets written
     * whatever its attempt said.
     */
    private function honestStatus(
        DeliveryAttempt $attempt,
        NotificationDeliveryProvider $provider,
    ): DeliveryStatus {
        if ($provider->isSimulated() && $attempt->status === DeliveryStatus::Sent) {
            return DeliveryStatus::Simulated;
        }

        return $attempt->status;
    }

    /**
     * Why push is not being attempted for this customer, or null.
     *
     * A preference can stop a push. It can never stop a notification, and
     * it can never reach this method for a transactional type — those are
     * the messages a customer needs in order to act on something they
     * started, and NotificationType decides which they are from the event
     * rather than from anything a caller or a customer set.
     */
    private function preferenceFor(Notification $notification): ?string
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $notification->user_id)
            ->first();

        if ($preference === null) {
            return null;
        }

        if (! $preference->push_enabled) {
            return 'push_disabled_by_customer';
        }

        if ($notification->type()->isTransactional()) {
            // Not mutable. A category mute cannot reach an application
            // status or a document problem.
            return null;
        }

        return in_array($notification->category(), $preference->mutedCategories(), true)
            ? 'category_muted_by_customer'
            : null;
    }

    private function write(
        Notification $notification,
        ?NotificationDevice $device,
        DeliveryStatus $status,
        ?string $reason = null,
        ?string $provider = null,
        bool $isSimulated = false,
    ): NotificationDelivery {
        /** @var NotificationDelivery $delivery */
        $delivery = $notification->deliveries()->updateOrCreate(
            ['notification_device_id' => $device?->getKey()],
            [
                'channel' => 'push',
                'provider' => $provider,
                'is_simulated' => $isSimulated,
                'status' => $status->value,
                'reason' => $reason,
                'attempted_at' => now(),
            ],
        );

        return $delivery;
    }

    /** Resolved per call, so a driver change in config is honoured. */
    private function provider(): NotificationDeliveryProvider
    {
        return app(NotificationDeliveryProvider::class);
    }
}
