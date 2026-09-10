<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Turns a verified mobile number into a session.
 */
class AuthService
{
    /**
     * @return array{user: User, token: NewAccessToken, is_new_user: bool}
     */
    public function signIn(string $mobile, ?string $deviceName = null): array
    {
        try {
            return $this->attemptSignIn($mobile, $deviceName);
        } catch (UniqueConstraintViolationException) {
            /*
             * Lost a first-sign-in race, and the retry has to be a whole new
             * transaction rather than another read inside this one.
             *
             * The attempt below opens its snapshot when it first looks for
             * the customer. The winner commits after that, so under
             * REPEATABLE READ nothing inside the losing transaction can see
             * the row it just collided with — not a re-read, not a locking
             * read, not createOrFirst's own recovery, which is why that
             * rethrows here. Only starting again picks up a snapshot that
             * includes the committed row, and the second pass then finds the
             * customer and signs them in normally.
             *
             * Once, deliberately. A second collision means something other
             * than a race, and a retry loop on the authentication path would
             * turn that into load.
             */
            return $this->attemptSignIn($mobile, $deviceName);
        }
    }

    /**
     * @return array{user: User, token: NewAccessToken, is_new_user: bool}
     */
    private function attemptSignIn(string $mobile, ?string $deviceName): array
    {
        return DB::transaction(function () use ($mobile, $deviceName) {
            $user = User::query()->where('mobile', $mobile)->first();
            $isNew = $user === null;

            if ($isNew) {
                /*
                 * Two first-time sign-ins for the same number can both find
                 * nothing and both insert. The unique index on `mobile` is
                 * what stops that becoming two customers.
                 *
                 * createOrFirst inserts inside a savepoint, so a collision
                 * leaves this transaction usable rather than poisoned. It
                 * cannot recover on its own here — its re-read runs in this
                 * transaction's snapshot, which predates the winner's commit
                 * — so it rethrows and signIn() retries in a fresh one.
                 * The savepoint is what makes that retry the only cost.
                 */
                $user = User::createOrFirst(['mobile' => $mobile]);

                // Whether this call actually inserted decides what the app
                // is told, and losing the race means the customer already
                // existed a moment before we looked again.
                $isNew = $user->wasRecentlyCreated;
            }

            // Both profiles exist from the first sign-in, so no downstream
            // service has to cope with a half-created customer.
            $user->ensureProfiles();
            $user->forceFill(['last_login_at' => now()])->save();

            $token = $user->createToken(
                $deviceName ?: 'fynnedge-app',
                ['*'],
                $this->tokenExpiry(),
            );

            return ['user' => $user, 'token' => $token, 'is_new_user' => $isNew];
        });
    }

    public function signOut(User $user): void
    {
        // Only the token that made this request, so signing out on a phone
        // does not sign the customer out of their tablet.
        $user->currentAccessToken()?->delete();
    }

    public function signOutEverywhere(User $user): void
    {
        $user->tokens()->delete();
    }

    private function tokenExpiry(): ?\DateTimeInterface
    {
        $minutes = config('sanctum.expiration');

        return $minutes === null ? null : now()->addMinutes((int) $minutes);
    }
}
