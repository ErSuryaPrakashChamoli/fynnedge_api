<?php

namespace App\Domain\Provider\Fixture;

use App\Domain\Money;
use App\Domain\Provider\CanonicalProductInput;
use App\Domain\Provider\NormalizationFailed;
use App\Domain\Provider\ProviderNormalizer;
use App\Domain\Provider\UnsupportedRequirement;

/**
 * Kestrel Financial's terms, in FynnEdge's.
 *
 * Every conversion here is explicit, and every one of them is a decision
 * somebody had to make by reading the provider's contract:
 *
 *   roiBasisPoints  1150   →  interest_rate         11.5
 *   tenureYearsMax  6      →  max_tenure_months     72
 *   segment         CONSUMER_TERM_CREDIT → category personal
 *
 * Where the contract does not settle a value, the row is refused. A basis
 * point count that might be a percentage, a tenure that might be months or
 * years, a category nobody mapped — each of those could be guessed, and a
 * guessed interest rate reaches a customer as a fact.
 */
class FixtureProviderNormalizer implements ProviderNormalizer
{
    /**
     * The provider's segments, mapped to FynnEdge categories.
     *
     * An explicit table, not fuzzy matching on the product's name. A segment
     * that is not here is refused rather than guessed at — "SME_TERM_CREDIT"
     * happens to look like a business loan, but a mapping that works by
     * looking plausible will eventually put a home loan in front of someone
     * asking for a personal one.
     */
    private const CATEGORIES = [
        'CONSUMER_TERM_CREDIT' => 'personal',
        'SME_TERM_CREDIT' => 'business',
        'HOME_FINANCE' => 'home',
        'PROPERTY_BACKED_CREDIT' => 'lap',
    ];

    /**
     * Provider requirements FynnEdge cannot evaluate, and their canonical
     * keys. Kept so Module 23 can find them; never turned into eligibility.
     */
    private const UNSUPPORTED_FIELDS = [
        'minimumBureauScore' => 'minimum_credit_score',
        'businessVintageMonths' => 'business_vintage_months',
        'bankingVintageMonths' => 'banking_vintage_months',
        'gstVintageMonths' => 'gst_vintage_months',
    ];

    /**
     * Fields that must never enter the catalogue.
     *
     * A product feed should carry products. If one arrives carrying a person,
     * the row is refused rather than stripped: a feed that contains customer
     * data is a feed that has gone wrong, and quietly cleaning it up would
     * hide that.
     */
    private const CUSTOMER_FIELDS = [
        'customerName', 'applicantName', 'mobile', 'phone', 'email',
        'pan', 'aadhaar', 'accountNumber', 'dateOfBirth', 'customerId',
    ];

    public function normalize(array $row): CanonicalProductInput
    {
        $ref = is_string($row['productRef'] ?? null) ? $row['productRef'] : null;

        $this->assertNoCustomerData($ref, $row);

        return new CanonicalProductInput(
            providerProductCode: $this->required($ref, $row, 'productRef'),
            displayName: $this->required($ref, $row, 'productTitle'),
            category: $this->category($ref, $row),
            discontinued: ($row['status'] ?? null) === 'DISCONTINUED',

            interestRate: $this->rate($ref, $row),
            processingFeePercent: $this->feePercent($ref, $row),
            processingFeeCap: $this->optionalMoney($ref, $row, 'feeCeilingRupees'),
            prepaymentCharge: (string) ($row['foreclosureTerms'] ?? ''),

            minAmount: $this->money($ref, $row, 'amountFloor'),
            maxAmount: $this->money($ref, $row, 'amountCeiling'),
            minTenureMonths: $this->tenureMonths($ref, $row, 'tenureYearsMin'),
            maxTenureMonths: $this->tenureMonths($ref, $row, 'tenureYearsMax'),

            minMonthlyIncome: $this->optionalMoney($ref, $row, 'incomeFloorMonthly'),
            minAge: $this->optionalInt($ref, $row, 'applicantAgeMin'),
            maxAgeAtMaturity: $this->optionalInt($ref, $row, 'applicantAgeMaxAtClosure'),

            suitedReasons: $this->strings($row['highlights'] ?? []),
            considerations: $this->strings($row['watchOuts'] ?? []),
            conditions: $this->strings($row['terms'] ?? []),

            unsupported: $this->unsupported($row),

            // This provider states no effective dates. The ingestion policy
            // decides what that means; the normalizer does not invent one.
            effectiveFrom: null,
            effectiveUntil: null,
        );
    }

    /**
     * Basis points to a percentage.
     *
     * The provider's contract says `roiBasisPoints`, and 1150 is 11.50%. A
     * bare `roi` would be ambiguous — 11.5 or 0.115 — and is refused rather
     * than interpreted.
     */
    private function rate(?string $ref, array $row): float
    {
        if (array_key_exists('roi', $row)) {
            throw new NormalizationFailed(
                $ref,
                "'roi' is ambiguous: this provider's contract defines rates "
                ."as 'roiBasisPoints', and a bare rate could be a percentage "
                .'or a fraction.',
            );
        }

        $points = $row['roiBasisPoints'] ?? null;

        if (! is_int($points) && ! is_float($points)) {
            throw new NormalizationFailed($ref, "'roiBasisPoints' is missing.");
        }

        if ($points < 0) {
            throw new NormalizationFailed($ref, 'the interest rate is negative.');
        }

        return round($points / 100, 3);
    }

    private function feePercent(?string $ref, array $row): float
    {
        $fee = $row['feePct'] ?? null;

        if (! is_int($fee) && ! is_float($fee)) {
            throw new NormalizationFailed($ref, "'feePct' is missing.");
        }

        if ($fee < 0) {
            throw new NormalizationFailed($ref, 'the processing fee is negative.');
        }

        return (float) $fee;
    }

    /** Years to months. The provider counts in years; FynnEdge does not. */
    private function tenureMonths(?string $ref, array $row, string $key): int
    {
        $years = $row[$key] ?? null;

        if (! is_int($years)) {
            throw new NormalizationFailed(
                $ref,
                "'{$key}' is missing or is not a whole number of years.",
            );
        }

        if ($years < 1) {
            throw new NormalizationFailed($ref, "'{$key}' is not a usable term.");
        }

        return $years * 12;
    }

    private function category(?string $ref, array $row): string
    {
        $segment = $row['segment'] ?? null;

        if (! is_string($segment) || ! isset(self::CATEGORIES[$segment])) {
            throw new NormalizationFailed(
                $ref,
                "segment '".(is_string($segment) ? $segment : 'null')
                ."' has no mapping to a FynnEdge category.",
            );
        }

        return self::CATEGORIES[$segment];
    }

    private function money(?string $ref, array $row, string $key): Money
    {
        $value = $row[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new NormalizationFailed($ref, "'{$key}' is missing.");
        }

        if ($value < 0) {
            throw new NormalizationFailed($ref, "'{$key}' is negative.");
        }

        // Whole rupees per this provider's contract, into integer paise.
        return Money::of($value);
    }

    private function optionalMoney(?string $ref, array $row, string $key): ?Money
    {
        return ($row[$key] ?? null) === null
            ? null
            : $this->money($ref, $row, $key);
    }

    private function optionalInt(?string $ref, array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new NormalizationFailed($ref, "'{$key}' is not a whole number.");
        }

        return $value;
    }

    private function required(?string $ref, array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new NormalizationFailed($ref, "'{$key}' is missing.");
        }

        return trim($value);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(
            fn ($v) => (string) $v,
            array_filter($value, 'is_scalar'),
        ));
    }

    /** @return list<UnsupportedRequirement> */
    private function unsupported(array $row): array
    {
        $found = [];

        foreach (self::UNSUPPORTED_FIELDS as $providerKey => $canonicalKey) {
            $value = $row[$providerKey] ?? null;

            if ($value !== null && is_scalar($value)) {
                $found[] = new UnsupportedRequirement(
                    $canonicalKey,
                    (string) $value,
                );
            }
        }

        return $found;
    }

    private function assertNoCustomerData(?string $ref, array $row): void
    {
        foreach (self::CUSTOMER_FIELDS as $field) {
            if (array_key_exists($field, $row)) {
                throw new NormalizationFailed(
                    $ref,
                    "the row carries '{$field}', which is customer data. A "
                    .'product feed must contain products.',
                );
            }
        }
    }
}
