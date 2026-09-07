<?php

namespace App\Models;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived figures live here rather than in controllers, so the API, the
 * scoring service and the rules engine all read the same definitions.
 */
class FinancialProfile extends Model
{
    protected $fillable = [
        'monthly_income',
        'monthly_expenses',
        'existing_emi',
        'savings',
        'other_obligations',
        'employment_type',
    ];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'decimal:2',
            'monthly_expenses' => 'decimal:2',
            'existing_emi' => 'decimal:2',
            'savings' => 'decimal:2',
            'other_obligations' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * These read through FinancialEngine rather than doing arithmetic here.
     * Keeping the accessors is convenient for callers; keeping the formulas
     * out of them is what stops definitions drifting between the model, the
     * resources and the scoring service.
     */
    private function snapshot(): FinancialSnapshot
    {
        return app(FinancialEngine::class)->snapshot($this);
    }

    /** What is left each month after everything currently committed. */
    public function surplus(): float
    {
        return (float) $this->snapshot()->surplus->toJsonNumber();
    }

    /** Existing EMIs as a share of income — the core affordability signal. */
    public function emiRatio(): float
    {
        return (float) $this->snapshot()->emiRatio->toJsonNumber();
    }

    public function expenseRatio(): float
    {
        return (float) $this->snapshot()->expenseRatio->toJsonNumber();
    }

    /** Everything that must be paid every month, EMIs included. */
    public function monthlyOutgo(): float
    {
        return (float) $this->snapshot()->totalOutgo->toJsonNumber();
    }

    /** How many months of outgoings the savings buffer covers. */
    public function emergencyMonths(): float
    {
        return (float) $this->snapshot()->emergencyMonths->toJsonNumber();
    }

    public function hasData(): bool
    {
        return (float) $this->monthly_income > 0;
    }
}
