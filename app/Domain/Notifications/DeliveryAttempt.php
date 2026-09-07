<?php

namespace App\Domain\Notifications;

/**
 * What one provider said about one push.
 *
 * A provider cannot return a bare success. It returns a status it is
 * entitled to write — and DeliveryStatus::Sent is entitled only to a
 * provider that is not simulated, which NotificationDelivery enforces
 * rather than trusts.
 */
final class DeliveryAttempt
{
    private function __construct(
        public readonly DeliveryStatus $status,
        public readonly ?string $reason = null,
    ) {}

    public static function sent(): self
    {
        return new self(DeliveryStatus::Sent);
    }

    public static function simulated(): self
    {
        return new self(DeliveryStatus::Simulated);
    }

    public static function failed(string $reason): self
    {
        return new self(DeliveryStatus::Failed, $reason);
    }

    public static function unavailable(string $reason = 'no_provider'): self
    {
        return new self(DeliveryStatus::Unavailable, $reason);
    }
}
