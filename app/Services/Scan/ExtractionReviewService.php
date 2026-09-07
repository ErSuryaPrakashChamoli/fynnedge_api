<?php

namespace App\Services\Scan;

use App\Domain\Money;
use App\Domain\Scan\ReviewDecision;
use App\Http\Requests\UpdateFinancialProfileRequest;
use App\Models\DocumentScan;
use App\Models\DocumentScanField;
use App\Models\DocumentScanReview;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The customer's answer to what a document appeared to say.
 *
 * This is the only place an extracted value can become the customer's data,
 * and it can only happen because they said so. Confirming records a
 * decision; applying it to their profile is a further, separate act.
 *
 * The extraction itself is never edited. A customer correcting ₹80,000 to
 * ₹75,000 leaves both facts standing — what the document appeared to say,
 * and what they say it is.
 */
class ExtractionReviewService
{
    /**
     * Which extracted fields can become which profile fields.
     *
     * Deliberately one entry. Monthly income is the single figure FynnEdge
     * has a home for and a rule for; net pay is what a customer receives, so
     * that is what maps. Gross pay is shown, is confirmable as a record of
     * what the document said, and reaches no profile field — because
     * FynnEdge has no rule that says a customer's monthly income is their
     * gross, and inventing one here would be a financial decision taken by
     * an OCR pipeline.
     */
    private const PROFILE_FIELDS = [
        'net_income' => 'monthly_income',
    ];

    public function __construct(
        private readonly CustomerService $customers,
    ) {}

    /** Whether confirming this field can update the customer's profile. */
    public static function profileFieldFor(string $key): ?string
    {
        return self::PROFILE_FIELDS[$key] ?? null;
    }

    /**
     * Records what the customer says about one field.
     *
     * Idempotent by construction: one standing decision per field per scan.
     * Tapping Confirm twice writes the same decision twice and mutates the
     * profile once, because the second call sees the value already applied.
     *
     * @throws ScanFieldNotFound|ValidationException
     */
    public function review(
        User $user,
        DocumentScan $scan,
        string $fieldKey,
        ReviewDecision $decision,
        ?string $customerValue = null,
        bool $applyToProfile = false,
    ): DocumentScanReview {
        $field = $scan->fields()->where('key', $fieldKey)->first();

        if ($field === null) {
            throw new ScanFieldNotFound;
        }

        $amount = null;
        $value = null;

        if ($decision->yieldsValue()) {
            [$value, $amount] = $this->resolveValue($field, $decision, $customerValue);
        }

        $existing = $scan->reviews()->where('field_key', $fieldKey)->first();

        // A profile that already carries this decision is not updated again.
        // Two taps are one mutation.
        $alreadyApplied = $existing !== null
            && $existing->applied_to_profile
            && $existing->decision === $decision->value
            && $existing->customer_value === $value;

        $applied = $alreadyApplied;

        if ($applyToProfile && ! $alreadyApplied && $decision->yieldsValue()) {
            $this->applyToProfile($user, $field, $amount, $value);
            $applied = true;
        }

        $review = DB::transaction(fn () => DocumentScanReview::updateOrCreate(
            [
                'document_scan_id' => $scan->getKey(),
                'field_key' => $fieldKey,
            ],
            [
                // The actor is the authenticated customer, always. Nothing
                // in a payload names who decided this.
                'user_id' => $user->getKey(),
                'decision' => $decision->value,

                // What the document appeared to say, copied at decision
                // time so the record stays readable on its own.
                'extracted_value' => $field->value,

                'customer_value' => $value,
                'customer_amount_paise' => $amount?->paise,
                'applied_to_profile' => $applied,
                'decided_at' => now(),
            ],
        ));

        $this->log(
            $decision === ReviewDecision::Rejected
                ? 'extraction_rejected'
                : 'extraction_confirmed',
            $scan,
            $fieldKey,
            $applied,
        );

        return $review;
    }

    /**
     * What the customer stands behind, as a value and as money.
     *
     * @return array{0: ?string, 1: ?Money}
     */
    private function resolveValue(
        DocumentScanField $field,
        ReviewDecision $decision,
        ?string $customerValue,
    ): array {
        if ($decision === ReviewDecision::Confirmed) {
            if ($field->value === null) {
                // There is nothing to confirm. A field the document did not
                // show cannot be agreed with.
                throw ValidationException::withMessages([
                    'decision' => 'There is no value to confirm for that field.',
                ]);
            }

            return [$field->value, $field->amount()];
        }

        if ($customerValue === null || trim($customerValue) === '') {
            throw ValidationException::withMessages([
                'value' => 'Enter the correct value.',
            ]);
        }

        if ($field->value_type !== 'money') {
            return [trim($customerValue), null];
        }

        // Money stays money. A corrected amount goes through the same exact
        // representation as every other rupee in FynnEdge, and a value that
        // is not a number is refused here rather than stored as text.
        $cleaned = str_replace(['₹', ',', ' '], '', trim($customerValue));

        if (preg_match('/^\d+(\.\d{1,2})?$/', $cleaned) !== 1) {
            throw ValidationException::withMessages([
                'value' => 'Enter an amount in rupees, for example 75000.',
            ]);
        }

        $money = Money::of($cleaned);

        return [$money->toDecimalString(), $money];
    }

    /**
     * Carries a confirmed figure into the customer's financial profile.
     *
     * Through the existing profile rules, not around them. The payload is
     * the customer's current profile with the one confirmed field replaced,
     * validated by exactly the rules PUT /financial-profile uses — so a
     * confirmed extraction cannot enter through a side door that a typed
     * figure could not.
     *
     * @throws ValidationException
     */
    private function applyToProfile(
        User $user,
        DocumentScanField $field,
        ?Money $amount,
        ?string $value,
    ): void {
        $target = self::profileFieldFor($field->key);

        if ($target === null) {
            throw new ScanFieldNotApplicable;
        }

        if ($amount === null) {
            throw ValidationException::withMessages([
                'value' => 'That value cannot be saved to your profile.',
            ]);
        }

        $profile = $this->customers->financialProfileFor($user);

        $payload = [
            'monthly_income' => (float) $profile->monthly_income,
            'monthly_expenses' => (float) $profile->monthly_expenses,
            'existing_emi' => (float) $profile->existing_emi,
            'other_obligations' => (float) $profile->other_obligations,
            'savings' => (float) $profile->savings,
            'employment_type' => $profile->employment_type,
            $target => (float) $amount->toDecimalString(),
        ];

        // The same rules the typed route uses, including the cross-field
        // check. Reused rather than restated: a second copy of financial
        // validation is a second thing to keep in step.
        $validator = Validator::make(
            $payload,
            UpdateFinancialProfileRequest::profileRules(),
            UpdateFinancialProfileRequest::profileMessages(),
        );

        UpdateFinancialProfileRequest::checkOutgoings($validator, $payload);

        $validator->validate();

        // The same service the typed route uses, so every downstream
        // recalculation — FynnScore, Home, FynnMatch — happens exactly as it
        // does when the customer edits the figure by hand.
        $this->customers->updateFinancialProfile($user, $validator->validated());
    }

    /**
     * Operational facts only. Never the value, never the document's text.
     */
    private function log(
        string $event,
        DocumentScan $scan,
        string $fieldKey,
        bool $applied,
    ): void {
        Log::info($event, [
            'scan_id' => $scan->getKey(),
            'document_id' => $scan->document_id,
            'user_id' => $scan->user_id,

            // Which field, not what was in it.
            'field' => $fieldKey,
            'applied_to_profile' => $applied,
        ]);
    }
}

/** That scan has no such field. */
class ScanFieldNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That scan has no such field.');
    }
}

/** That field has nowhere to go in a customer's profile. */
class ScanFieldNotApplicable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That field cannot be saved to your profile.');
    }
}
