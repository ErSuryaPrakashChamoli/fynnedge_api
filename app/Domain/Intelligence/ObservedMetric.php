<?php

namespace App\Domain\Intelligence;

use App\Domain\Money;

/**
 * A figure FynnEdge has actually written down more than once.
 *
 * Every case corresponds to a column on `financial_observations`, and that
 * is the whole of the list. A metric FynnEdge does not store cannot have a
 * history, and adding a case here without a column behind it would be the
 * first step to inventing one.
 *
 * The ratios FynnScore reads — emi_ratio and expense_ratio — are stored but
 * are deliberately not cases. They appear inside a score change, as the
 * figures that were true when each score was calculated, which is the one
 * thing that can be said about them without claiming a cause.
 */
enum ObservedMetric: string
{
    case Score = 'fynn_score';
    case MonthlyIncome = 'monthly_income';
    case MonthlyOutgo = 'monthly_outgo';
    case Surplus = 'surplus';
    case EmergencyMonths = 'emergency_months';

    public function label(): string
    {
        return match ($this) {
            self::Score => 'FynnScore',
            self::MonthlyIncome => 'Recorded monthly income',
            self::MonthlyOutgo => 'Recorded monthly outgo',
            self::Surplus => 'Recorded monthly surplus',
            self::EmergencyMonths => 'Emergency buffer',
        };
    }

    /**
     * Where the figure came from.
     *
     * Only the income is something the customer typed. The outgo is the sum
     * of what they typed, the surplus is a subtraction, the buffer is a
     * division and the score is the model — all of them FinancialEngine's
     * or FynnScoreEngine's, none of them a provider's and none simulated.
     */
    public function source(): string
    {
        return match ($this) {
            self::MonthlyIncome => 'customer_provided',
            default => 'calculated',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::Score => 'points',
            self::EmergencyMonths => 'months',
            default => 'inr',
        };
    }

    public function isMoney(): bool
    {
        return $this->unit() === 'inr';
    }

    /**
     * The figure as a customer reads it.
     *
     * Money goes through Money's own Indian grouping — number_format gives
     * ₹150,000 where this audience reads ₹1,50,000, and a history that
     * printed figures differently from the screens they came off would look
     * like a different product's numbers.
     *
     * Signed, because a figure in this list stands on its own. A surplus of
     * -₹8,000 has no sentence around it to carry the direction, and printed
     * as ₹8,000 it would simply be the wrong number.
     */
    public function format(int|float $value): string
    {
        return match ($this) {
            self::Score => (string) $value,
            self::EmergencyMonths => $this->months((float) $value),
            default => Money::of($value)->toSignedIndianString(),
        };
    }

    private function months(float $value): string
    {
        $trimmed = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $trimmed === '1' ? '1 month' : $trimmed.' months';
    }
}
