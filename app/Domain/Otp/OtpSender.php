<?php

namespace App\Domain\Otp;

/**
 * Delivers a one-time code to a mobile number.
 *
 * The only thing a production SMS provider has to do is implement this. No
 * caller knows or cares which implementation is bound.
 */
interface OtpSender
{
    public function send(string $mobile, string $code): void;

    /**
     * The code to show in the app during development, or null when delivery
     * is real. This is what keeps a dev build usable without an SMS gateway,
     * and it must return null the moment a real provider is configured.
     */
    public function developmentHint(string $code): ?string;
}
