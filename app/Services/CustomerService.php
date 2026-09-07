<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\Notifications\CustomerNotifications;

/**
 * Writes to the customer's own records. Controllers stay thin; this is where
 * the rules about *how* a profile changes live.
 */
class CustomerService
{
    public function profileFor(User $user): CustomerProfile
    {
        return $user->customerProfile ?? $user->customerProfile()->create([]);
    }

    public function financialProfileFor(User $user): FinancialProfile
    {
        return $user->financialProfile ?? $user->financialProfile()->create([]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateProfile(User $user, array $attributes): CustomerProfile
    {
        $profile = $this->profileFor($user);
        $profile->fill($attributes);

        // Onboarding is complete once we know who they are and what they want.
        if ($profile->onboarding_completed_at === null
            && $profile->isComplete()
            && filled($profile->intent)) {
            $profile->onboarding_completed_at = now();
        }

        $profile->save();

        return $profile->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateFinancialProfile(User $user, array $attributes): FinancialProfile
    {
        $profile = $this->financialProfileFor($user);
        $profile->fill($attributes)->save();

        /*
         * Their FynnScore may have moved with it.
         *
         * The notification layer decides whether that is worth saying —
         * most edits move the score by a point or two and change nothing
         * the customer is shown. This call says only that the inputs
         * changed.
         */
        app(CustomerNotifications::class)->financialProfileChanged($user);

        return $profile->fresh();
    }
}
