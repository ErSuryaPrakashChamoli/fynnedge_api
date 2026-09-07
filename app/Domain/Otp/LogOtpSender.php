<?php

namespace App\Domain\Otp;

use Illuminate\Support\Facades\Log;

/**
 * Development delivery: writes the code to the log and returns it to the app
 * so the OTP screen can be used without an SMS gateway.
 *
 * Refuses to run in production — a build that reaches production without a
 * real provider configured should fail loudly, not quietly hand out codes.
 */
class LogOtpSender implements OtpSender
{
    public function send(string $mobile, string $code): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'LogOtpSender must not be used in production. '
                .'Configure a real provider via FYNNEDGE_OTP_DRIVER.',
            );
        }

        Log::info('FynnEdge development OTP issued', [
            'mobile' => $this->maskMobile($mobile),
            'code' => $code,
        ]);
    }

    public function developmentHint(string $code): ?string
    {
        return app()->environment('production') ? null : $code;
    }

    /** Never write a full number to the log. */
    private function maskMobile(string $mobile): string
    {
        return strlen($mobile) <= 4
            ? '****'
            : str_repeat('*', strlen($mobile) - 4).substr($mobile, -4);
    }
}
