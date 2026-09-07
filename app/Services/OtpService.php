<?php

namespace App\Services;

use App\Domain\Otp\OtpSender;
use App\Exceptions\OtpException;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Issues and verifies one-time codes.
 *
 * Everything security-relevant about OTP lives here: codes are hashed, an
 * outstanding challenge is reused rather than stacked, attempts are counted,
 * and a used challenge can never be replayed.
 */
class OtpService
{
    public function __construct(private readonly OtpSender $sender) {}

    /**
     * @return array{reference: string, expires_in: int, dev_hint: ?string}
     */
    public function issue(string $mobile, ?string $ip = null): array
    {
        // Any earlier live challenge for this number is retired, so a
        // resend cannot leave two valid codes in circulation.
        OtpChallenge::query()
            ->where('mobile', $mobile)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();
        $ttl = (int) config('fynnedge.otp.ttl_seconds');

        $challenge = OtpChallenge::create([
            'reference' => (string) Str::uuid(),
            'mobile' => $mobile,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addSeconds($ttl),
            'request_ip' => $ip,
        ]);

        $this->sender->send($mobile, $code);

        return [
            'reference' => $challenge->reference,
            'expires_in' => (int) config('fynnedge.otp.resend_after_seconds'),
            'dev_hint' => $this->sender->developmentHint($code),
        ];
    }

    /**
     * Consumes the challenge on success. Throws on any failure.
     *
     * @throws OtpException
     */
    public function verify(string $mobile, string $reference, string $code): void
    {
        $challenge = OtpChallenge::query()
            ->where('reference', $reference)
            ->where('mobile', $mobile)
            ->first();

        if ($challenge === null) {
            throw OtpException::invalid();
        }

        if ($challenge->isExhausted()) {
            throw OtpException::tooManyAttempts();
        }

        if ($challenge->isConsumed() || $challenge->isExpired()) {
            throw OtpException::expired();
        }

        // Count the attempt before checking, so a crash mid-verify cannot be
        // used to get unlimited guesses.
        $challenge->increment('attempts');

        if (! Hash::check($code, $challenge->code_hash)) {
            throw OtpException::invalid();
        }

        $challenge->update(['consumed_at' => now()]);
    }

    /**
     * Cryptographically random, zero-padded, fixed length.
     */
    private function generateCode(): string
    {
        $length = (int) config('fynnedge.otp.length');
        $max = (10 ** $length) - 1;

        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }
}
