<?php

namespace App\Services\Kyc;

use App\Domain\Kyc\MatchStatus;
use App\Http\Requests\UpdateCustomerProfileRequest;
use App\Models\KycFieldMatch;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * What the customer decides to do about a difference.
 *
 * A verification service reporting a different name is evidence, not an
 * instruction. Two things could be true: the profile is wrong, or the
 * document is not the one FynnEdge should be comparing against. FynnEdge
 * cannot tell which, and neither can the provider — so it asks.
 *
 * The two choices are deliberately symmetrical. Keeping the profile is a
 * real, recorded decision, not merely declining to act, because a customer
 * who has looked at a difference and decided their profile is right has told
 * FynnEdge something worth remembering.
 *
 * Nothing here writes a name FynnEdge was not explicitly told to write.
 */
class IdentityResolutionService
{
    /**
     * Which verified fields a customer may carry into their profile.
     *
     * Deliberately one. Name is the only identity field FynnEdge holds and
     * therefore the only one it can meaningfully be asked to correct.
     *
     * Date of birth, address, gender and document numbers are absent by
     * design: FynnEdge does not store them, and adding a column so that a
     * verification result would have somewhere to land is how a product
     * ends up holding identity data it never needed. See
     * App\Domain\Kyc\IdentityFieldPurpose.
     */
    private const PROFILE_FIELDS = [
        'name' => 'full_name',
    ];

    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    public static function profileFieldFor(string $field): ?string
    {
        return self::PROFILE_FIELDS[$field] ?? null;
    }

    /**
     * The customer keeps what is on their profile.
     *
     * Recorded rather than ignored: they looked at the difference and
     * decided. The verification keeps saying what it said — the evidence is
     * not edited by the decision about it.
     */
    public function keepProfile(User $user, KycVerification $verification, string $field): void
    {
        $match = $this->matchFor($verification, $field);

        $this->log('kyc_difference_kept', $verification, $field, false);

        $match->update(['reason' => 'You confirmed the name on your profile '
            .'is correct.']);
    }

    /**
     * The customer corrects their profile to what the document says.
     *
     * Through the existing profile rules, not around them. A verified value
     * gets no easier a path into the profile than a typed one: it goes
     * through exactly the validation PUT /profile uses, and through the same
     * service, so everything downstream happens as it always does.
     *
     * @throws ValidationException|KycFieldNotResolvable
     */
    public function updateProfile(
        User $user,
        KycVerification $verification,
        string $field,
    ): void {
        $match = $this->matchFor($verification, $field);
        $target = self::profileFieldFor($field);

        if ($target === null) {
            throw new KycFieldNotResolvable;
        }

        if ($match->status !== MatchStatus::Mismatched->value) {
            // There is no difference to resolve. Writing the verified value
            // anyway would be a profile change nobody asked for.
            throw ValidationException::withMessages([
                'field' => 'There is no difference to resolve for that field.',
            ]);
        }

        if ($match->verified_value === null) {
            throw ValidationException::withMessages([
                'field' => 'There is no verified value to use.',
            ]);
        }

        // The same rules the typed route uses. Reused rather than restated:
        // a second copy of profile validation is a second thing to keep in
        // step, and a verified name is still just a name.
        $validated = Validator::make(
            [$target => $match->verified_value],
            [$target => UpdateCustomerProfileRequest::profileRules()[$target]],
            UpdateCustomerProfileRequest::profileMessages(),
        )->validate();

        $this->customers->updateProfile($user, $validated);

        $match->update(['reason' => 'You updated your profile to match the '
            .'verified document.']);

        $this->log('kyc_difference_resolved', $verification, $field, true);
    }

    private function matchFor(KycVerification $verification, string $field): KycFieldMatch
    {
        $match = $verification->matches()->where('field', $field)->first();

        if ($match === null) {
            throw new KycFieldNotFound;
        }

        return $match;
    }

    /**
     * Operational facts only. Never the name, never the document number.
     */
    private function log(
        string $event,
        KycVerification $verification,
        string $field,
        bool $profileChanged,
    ): void {
        Log::info($event, [
            'verification_id' => $verification->getKey(),
            'document_id' => $verification->document_id,
            'user_id' => $verification->user_id,

            // Which field, not what was in it.
            'field' => $field,
            'profile_changed' => $profileChanged,
        ]);
    }
}

/** That verification has no such field comparison. */
class KycFieldNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That verification has no such field.');
    }
}

/** That field has nowhere to go in a customer's profile. */
class KycFieldNotResolvable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That field cannot be saved to your profile.');
    }
}
