<?php

namespace App\Domain\Notifications;

/**
 * What is actually known about one push attempt.
 *
 * The distinction that matters is between Sent and Simulated. Sent means a
 * provider accepted the message; Simulated means a fixture pretended to.
 * They are separate cases rather than a flag on one, because a single
 * `sent` state with a boolean beside it is a state that eventually gets
 * read without the boolean.
 *
 * There is no "delivered". A push provider can say it accepted a message;
 * whether a handset displayed it is not something FynnEdge is told, and a
 * state claiming otherwise would be a claim nothing backs.
 */
enum DeliveryStatus: string
{
    /** Nothing has been attempted yet. */
    case Pending = 'pending';

    /** A real provider accepted it. Only a real provider may write this. */
    case Sent = 'sent';

    /** A fixture stood in for a provider. Nothing left FynnEdge. */
    case Simulated = 'simulated';

    /** A provider was reachable and refused it. */
    case Failed = 'failed';

    /** There is no push provider configured, so there was nothing to try. */
    case Unavailable = 'unavailable';

    /** The customer has no registered device to push to. */
    case NoDevice = 'no_device';

    /** Whether a real provider genuinely accepted this message. */
    public function reachedAProvider(): bool
    {
        return $this === self::Sent;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Not attempted yet',
            self::Sent => 'Accepted by the push provider',
            self::Simulated => 'Simulated — nothing was sent',
            self::Failed => 'The push provider refused it',
            self::Unavailable => 'No push provider is connected',
            self::NoDevice => 'No registered device',
        };
    }
}
