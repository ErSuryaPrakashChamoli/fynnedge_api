<?php

namespace App\Domain\Notifications;

use App\Models\Notification;
use App\Models\NotificationDevice;

/**
 * The default: there is nowhere to push to.
 *
 * FynnEdge has no push provider. This is what that looks like in code — an
 * honest unavailable, not an empty implementation that returns success and
 * lets the rest of the system believe a customer's phone lit up.
 */
final class UnavailableNotificationDeliveryProvider implements NotificationDeliveryProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function isSimulated(): bool
    {
        // Not simulated. Simulation is a thing a fixture does; this
        // provider does nothing at all.
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function push(Notification $notification, NotificationDevice $device): DeliveryAttempt
    {
        return DeliveryAttempt::unavailable('no_push_provider_configured');
    }
}
