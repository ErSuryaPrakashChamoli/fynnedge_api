<?php

namespace App\Services;

use App\Models\CustomerProfile;
use App\Models\FinancialProfile;
use App\Models\User;
use App\Services\Intelligence\FinancialIntelligence;
use App\Services\Intelligence\FinancialMoments;
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
        $profile->fill($attributes);

        /*
         * Nothing moved, so nothing happened.
         *
         * Saving the form without touching a figure is a request that
         * succeeded and a financial life that did not change. It used to
         * run the whole pipeline anyway — a notification considered, a
         * score compared with itself, and a row written to the customer's
         * history saying precisely what the row above it said.
         *
         * Asked of the model rather than by re-reading the row: this is the
         * same object that is about to be saved, and its own account of
         * which attributes moved.
         */
        if (! $profile->isDirty()) {
            return $profile->fresh();
        }

        $profile->save();

        /*
         * Their FynnScore may have moved with it.
         *
         * The notification layer decides whether that is worth saying —
         * most edits move the score by a point or two and change nothing
         * the customer is shown. This call says only that the inputs
         * changed.
         */
        app(CustomerNotifications::class)->financialProfileChanged($user);

        /*
         * And FynnEdge writes down what is now true.
         *
         * A separate decision from the notification, deliberately: Module
         * 29 decides whether to interrupt the customer, and this decides
         * whether anything happened worth remembering. Most edits produce
         * neither.
         *
         * This is also the only reason FynnEdge will ever be able to say
         * "your score went from 64 to 71" — before Module 31 the previous
         * value was destroyed by the edit that replaced it.
         */
        app(FinancialMoments::class)->financialProfileChanged(
            $user,
            app(FinancialIntelligence::class),
        );

        return $profile->fresh();
    }
}
