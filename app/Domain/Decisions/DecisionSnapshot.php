<?php

namespace App\Domain\Decisions;

/**
 * What FynnEdge showed, at the moment it showed it.
 *
 * A decision record is evidence, not a second financial engine. What is
 * kept is the handful of figures needed to reproduce the sentence the
 * customer read — the amount and tenure they entered, the rate the
 * catalogue published, the EMI FynnEdge worked out, and enough of their
 * financial state to explain the ratio beside it.
 *
 * This is the canonical object every producer must build, which makes it
 * where the refusal lives. Modules 23 to 31 taught the same lesson four
 * times: a rule about what may be stored, enforced only by the one caller
 * that happens to obey it, protects nothing once a second caller exists.
 * So the keys are an allowlist and anything else is refused outright,
 * rather than filtered quietly — a producer trying to write a PAN into a
 * decision record is a bug, and dropping the field would leave the bug in
 * place for the next one.
 */
final class DecisionSnapshot
{
    /**
     * Everything a decision record may hold, and nothing else.
     *
     * Figures the customer entered, figures a lender published, and figures
     * FynnEdge derived from those. No identity, no document, no provider
     * payload, no account.
     *
     * @var list<string>
     */
    public const ALLOWED = [
        // What the customer put in.
        'amount',
        'tenure_months',
        'monthly_change',
        'monthly_amount',
        'months',
        'scenario',

        // What the catalogue published.
        'interest_rate',
        'processing_fee_percent',

        // What FynnEdge worked out.
        'emi',
        'total_interest',
        'total_payable',
        'processing_fee',

        // Enough of their state to explain the ratio beside it.
        'monthly_income',
        'monthly_outgo',
        'existing_emi',
        'surplus',
        'emi_ratio',
        'projected_emi_ratio',
        'expense_ratio',
        'emergency_months',
        'fynn_score',
        'fynn_score_band',

        // Which products, for a comparison.
        'product_codes',
    ];

    /** @param array<string, mixed> $values */
    private function __construct(public readonly array $values) {}

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws RefusedDecisionData
     */
    public static function of(array $values): self
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if ($value === null) {
                // An absent figure is absent, not stored as a null that a
                // reader could mistake for zero.
                continue;
            }

            self::refuse($key, $value);

            $clean[$key] = $value;
        }

        return new self($clean);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @throws RefusedDecisionData
     */
    private static function refuse(string $key, mixed $value): void
    {
        if (! in_array($key, self::ALLOWED, true)) {
            throw new RefusedDecisionData(
                $key,
                'it is not one of the figures a decision record keeps. A '
                .'decision is evidence of what was shown, not a copy of the '
                .'customer\'s file.',
            );
        }

        /*
         * And a check on the value, not only the name.
         *
         * An allowlisted key with a document number in it is the same leak
         * as a key called `pan`. This is a net rather than a proof — it
         * cannot recognise every sensitive value — but it fails the moment
         * somebody puts a provider field where a figure belongs, which is
         * how these things actually happen.
         */
        foreach (self::flatten($value) as $scalar) {
            if (! is_string($scalar)) {
                continue;
            }

            $rules = [
                'pan' => '/\b[A-Z]{5}[0-9]{4}[A-Z]\b/',
                'long_number' => '/[0-9]{9,}/',
                'storage_key' => '#documents/u[0-9]+/|\b[0-9A-HJKMNP-TV-Z]{26}\.\w+\b#i',
                'digest' => '/\b[0-9a-f]{64}\b/i',
                'token' => '/\b[0-9]+\|[A-Za-z0-9]{20,}\b/',
            ];

            foreach ($rules as $rule => $pattern) {
                if (preg_match($pattern, $scalar) === 1) {
                    throw new RefusedDecisionData(
                        $key,
                        'it contains something shaped like a value FynnEdge '
                        ."does not put in a decision record [{$rule}]",
                    );
                }
            }
        }
    }

    /** @return list<mixed> */
    private static function flatten(mixed $value): array
    {
        if (! is_array($value)) {
            return [$value];
        }

        $flat = [];

        foreach ($value as $item) {
            $flat = array_merge($flat, self::flatten($item));
        }

        return $flat;
    }
}
