<?php

namespace App\Domain\Financial;

/**
 * Where the customer currently stands, in words.
 *
 * A reading of FinancialSnapshot and nothing else. Every figure it reports
 * was produced by FinancialEngine; this class divides none of them, adds
 * none of them and defines no threshold of its own — the six-month
 * reference below is FinancialEngine's, named rather than copied.
 *
 * It exists because the same three sentences were about to be written in
 * three places: the plan header, the buffer rung of the action ladder, and
 * the Flutter card that renders both. Copy that describes a customer's
 * money is as much a source of truth as the arithmetic behind it, and two
 * versions of it drift.
 *
 * Everything here is a statement about what FynnEdge has recorded. There is
 * no prediction, no projection, no "on track", and no suggestion of what to
 * do about any of it — that is the action ladder's job, and it is
 * deliberately not this class's.
 */
final class FinancialPosition
{
    private function __construct(private readonly FinancialSnapshot $snapshot) {}

    public static function from(FinancialSnapshot $snapshot): self
    {
        return new self($snapshot);
    }

    /** Whether there is an income figure to measure anything against. */
    public function isMeasurable(): bool
    {
        return $this->snapshot->hasIncome;
    }

    public function state(): SurplusState
    {
        if (! $this->isMeasurable()) {
            return SurplusState::Unknown;
        }

        $surplus = $this->snapshot->surplus;

        return match (true) {
            $surplus->isNegative() => SurplusState::Negative,
            $surplus->isZero() => SurplusState::Matched,
            default => SurplusState::Positive,
        };
    }

    /**
     * The position as a sentence the customer can check against their own
     * profile.
     *
     * "Recorded" carries the weight. FynnEdge knows what it was told; it
     * does not know what somebody earns.
     */
    public function statement(): string
    {
        return match ($this->state()) {
            SurplusState::Positive => 'Your recorded income is higher than '
                .'your monthly outgo.',
            SurplusState::Matched => 'Your recorded income currently matches '
                .'your monthly outgo.',
            SurplusState::Negative => 'Your recorded monthly outgo is higher '
                .'than your recorded income.',
            SurplusState::Unknown => 'FynnEdge has no monthly income '
                .'recorded, so it cannot work out your position.',
        };
    }

    /**
     * Whether "how many months would my savings cover" has an answer.
     *
     * It does not when nothing is recorded as going out. Ratio::timesOver
     * returns zero for a zero denominator — which is the right thing for it
     * to do and the wrong thing to read as a fact, because a customer with
     * no recorded outgo would be told their savings cover less than a month
     * when in truth there is nothing for them to cover.
     *
     * This is a real defect the plan would have surfaced: a customer who
     * entered an income and no expenses is reachable through the existing
     * profile form.
     */
    public function bufferIsMeasurable(): bool
    {
        return $this->snapshot->totalOutgo->isPositive();
    }

    public function bufferMonths(): float
    {
        return (float) $this->snapshot->emergencyMonths->toJsonNumber();
    }

    /**
     * Below the reference the product already uses.
     *
     * False when the question has no answer, so an absent figure never
     * reads as a thin buffer.
     */
    public function bufferIsBelowReference(): bool
    {
        return $this->bufferIsMeasurable()
            && $this->bufferMonths() < FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS;
    }

    /**
     * The buffer as a sentence, or null when there is nothing to say.
     *
     * The same words whether it arrives on the plan or as the buffer rung
     * of the action ladder, because it is the same fact about the same
     * customer read from the same snapshot.
     */
    public function bufferStatement(): ?string
    {
        if (! $this->bufferIsMeasurable()) {
            return null;
        }

        return 'Your recorded savings would cover '
            .self::describeMonths($this->bufferMonths())
            .' of your monthly outgo. FynnEdge measures against '
            .FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS.' months.';
    }

    /**
     * How long the savings would last, in words that read.
     *
     * "about 0 months" is arithmetically right and sounds like a bug. A
     * customer with nothing saved is told less than a month, and one with
     * exactly one is told a month rather than 1 months.
     */
    public static function describeMonths(float $months): string
    {
        if ($months < 1) {
            return 'less than a month';
        }

        $rounded = rtrim(rtrim(number_format($months, 1, '.', ''), '0'), '.');

        return $rounded === '1' ? 'about a month' : "about {$rounded} months";
    }

    /**
     * The position as the app receives it.
     *
     * Figures and the sentences that describe them. No ratios the customer
     * has no use for, no inputs they already typed, and nothing that
     * identifies anything.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'is_measurable' => $this->isMeasurable(),
            'surplus_state' => $this->state()->value,
            'statement' => $this->statement(),

            'income' => $this->snapshot->income->toJsonNumber(),
            'monthly_outgo' => $this->snapshot->totalOutgo->toJsonNumber(),
            'surplus' => $this->snapshot->surplus->toJsonNumber(),

            'emergency_months' => $this->bufferIsMeasurable()
                ? $this->snapshot->emergencyMonths->toJsonNumber()
                : null,
            'emergency_reference_months' => FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS,
            'emergency_below_reference' => $this->bufferIsBelowReference(),
            'emergency_statement' => $this->bufferStatement(),
        ];
    }
}
