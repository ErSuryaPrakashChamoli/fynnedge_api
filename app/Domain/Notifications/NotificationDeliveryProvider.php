<?php

namespace App\Domain\Notifications;

use App\Models\Notification;
use App\Models\NotificationDevice;

/**
 * Somewhere a push notification could actually go.
 *
 * The same boundary shape as every other provider seam in FynnEdge: an
 * interface, an unavailable default, a fixture that is explicit about being
 * one, and a config driver that is `none` until somebody connects something
 * real. There is no silent fallback — a provider that is not there does not
 * quietly become a success.
 *
 * NO LIVE PUSH PROVIDER IS CONNECTED. There is no FCM integration, no APNs
 * integration, no credentials and no device tokens. When one is written it
 * implements this and nothing above it changes.
 */
interface NotificationDeliveryProvider
{
    /** Whether this provider can be used at all. */
    public function isAvailable(): bool;

    /**
     * Whether this provider only pretends to send.
     *
     * A simulated provider must say so, every time. Nothing infers it from
     * the environment, the driver name or the absence of credentials.
     */
    public function isSimulated(): bool;

    /** How this provider is named in a delivery record. */
    public function name(): string;

    /**
     * Attempt one push.
     *
     * Receives the notification so a real adapter can compose a payload
     * from the same title and body the customer sees in the app — never a
     * second, richer payload assembled from somewhere else.
     */
    public function push(Notification $notification, NotificationDevice $device): DeliveryAttempt;
}
