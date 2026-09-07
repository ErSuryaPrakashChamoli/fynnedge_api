<?php

namespace App\Services;

use App\Models\User;
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
        return DB::transaction(function () use ($mobile, $deviceName) {
            $user = User::query()->where('mobile', $mobile)->first();
            $isNew = $user === null;

            if ($isNew) {
                $user = User::create(['mobile' => $mobile]);
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
