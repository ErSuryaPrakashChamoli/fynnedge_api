<?php

namespace App\Services;

use App\Domain\Otp\OtpSender;
use App\Exceptions\OtpException;
use App\Models\OtpChallenge;
use Illuminate\Support\Facades\DB;
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
        $code = $this->generateCode();
        $ttl = (int) config('fynnedge.otp.ttl_seconds');

        /*
         * Insert first, then retire everything older than what was just
         * inserted — rather than retiring first and inserting after.
         *
         * Written the other way round, two resends arriving together both
         * find nothing live to retire and both insert, and the number ends
         * up holding two codes that each work. That is exactly what the
         * retire step exists to prevent. Both reach the same handset, so it
         * opens nobody else's account; it doubles the guessing surface for
         * this one and makes a stated invariant untrue.
         *
         * Ordering by id rather than locking is deliberate. The obvious fix
         * — take the lock before reading — locks a range that matches no
         * rows, and InnoDB answers that with gap locks: measured, eight
         * concurrent sends deadlocked seven of themselves, turning a
         * doubled code into a 500 for somebody who tapped resend twice. A
         * later insert always carries a higher id, so retiring strictly
         * below the new one converges on the newest challenge without any
         * request waiting on another.
         */
        $challenge = DB::transaction(function () use ($mobile, $code, $ttl, $ip) {
            $challenge = OtpChallenge::create([
                'reference' => (string) Str::uuid(),
                'mobile' => $mobile,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addSeconds($ttl),
                'request_ip' => $ip,
            ]);

            OtpChallenge::query()
                ->where('mobile', $mobile)
                ->whereNull('consumed_at')
                ->where('id', '<', $challenge->getKey())
                ->update(['consumed_at' => now()]);

            return $challenge;
        });

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
        /*
         * The whole check runs inside one transaction holding a row lock.
         *
         * Read, decide, then write leaves a window between "is this
         * consumed?" and "mark it consumed" in which a second request can
         * read the same unconsumed row. Both then pass, and one code has
         * bought two sessions. The window is small, and small is not the
         * same as closed — it widens under exactly the load an attacker
         * would create.
         *
         * SQLite ignores the lock and serialises writes anyway, so the
         * tests behave the same as production.
         */
        $failure = DB::transaction(function () use ($mobile, $reference, $code) {
            $challenge = OtpChallenge::query()
                ->where('reference', $reference)
                ->where('mobile', $mobile)
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return 'invalid';
            }

            if ($challenge->isExhausted()) {
                return 'exhausted';
            }

            if ($challenge->isConsumed() || $challenge->isExpired()) {
                return 'expired';
            }

            // Count the attempt before checking, so a crash mid-verify cannot
            // be used to get unlimited guesses.
            $challenge->increment('attempts');

            if (! Hash::check($code, $challenge->code_hash)) {
                return 'invalid';
            }

            $challenge->update(['consumed_at' => now()]);

            return null;
        });

        /*
         * Thrown out here, never inside the transaction.
         *
         * A wrong code has to leave the attempt counter raised, and an
         * exception crossing the transaction boundary would roll that
         * increment back — turning the cap on guesses into no cap at all.
         * The failure is decided under the lock and reported after it.
         */
        match ($failure) {
            'invalid' => throw OtpException::invalid(),
            'exhausted' => throw OtpException::tooManyAttempts(),
            'expired' => throw OtpException::expired(),
            default => null,
        };
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
