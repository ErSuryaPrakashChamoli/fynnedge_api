<?php

namespace App\Models;

use App\Domain\Intelligence\FinancialObservationReading;
use App\Domain\Intelligence\ObservationReason;
use App\Domain\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was true about a customer's money at one moment.
 *
 * Written, never updated. An observation that could be revised would be a
 * record of what FynnEdge currently believes rather than of what it saw.
 */
class FinancialObservation extends Model
{
    protected $fillable = [
        'reason',
        'score',
        'score_band',
        'score_model_version',
        'monthly_income',
        'monthly_outgo',
        'surplus',
        'emi_ratio',
        'expense_ratio',
        'emergency_months',
        'observed_at',
    ];

    protected function casts(): array
    {
        return ['score' => 'integer', 'observed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reason(): ObservationReason
    {
        return ObservationReason::from($this->reason);
    }

    /**
     * A read-only view for the intelligence rules.
     *
     * Deliberately not the model itself: a rule comparing today with the
     * past must not be able to write to the past.
     */
    public function reading(): FinancialObservationReading
    {
        return new FinancialObservationReading(
            score: $this->score,
            scoreBand: $this->score_band,
            scoreModelVersion: $this->score_model_version,
            emiRatio: (float) $this->emi_ratio,
            expenseRatio: (float) $this->expense_ratio,
            emergencyMonths: (float) $this->emergency_months,
            observedAt: $this->observed_at,

            monthlyIncome: Money::of($this->monthly_income),
            monthlyOutgo: Money::of($this->monthly_outgo),
            surplus: Money::of($this->surplus),

            /*
             * An income was recorded if the figure is positive.
             *
             * The same rule FinancialSnapshot uses for `hasIncome`, applied
             * to what was stored rather than to what is true now — so a
             * customer who had entered nothing then reads as unmeasured
             * then, whatever they have entered since.
             */
            hasIncome: Money::of($this->monthly_income)->isPositive(),
        );
    }
}
