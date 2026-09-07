<?php

namespace App\Domain\Notifications\Fixture;

use App\Domain\Notifications\DeliveryAttempt;
use App\Domain\Notifications\NotificationDeliveryProvider;
use App\Models\Notification;
use App\Models\NotificationDevice;
use RuntimeException;

/**
 * A stand-in for a push provider, for developing the delivery path.
 *
 * It sends nothing. Every attempt is recorded as Simulated, which is a
 * different state from Sent everywhere it is read, so no screen, no test
 * and no operator can mistake one for the other.
 *
 * Refused in production twice over — by isAvailable() and again by push()
 * — for the same reason the lender fixture is: an environment misconfigured
 * in production must fail, not quietly start pretending to notify real
 * customers.
 */
final class MockNotificationDeliveryProvider implements NotificationDeliveryProvider
{
    public function isAvailable(): bool
    {
        return ! app()->environment('production');
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'mock';
    }

    public function push(Notification $notification, NotificationDevice $device): DeliveryAttempt
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'The mock push provider must never run in production.'
            );
        }

        /*
         * Nothing is sent, nothing is logged, and the token is not read.
         * A fixture that formatted a payload would be a fixture that could
         * one day be given a socket.
         */
        return DeliveryAttempt::simulated();
    }
}
