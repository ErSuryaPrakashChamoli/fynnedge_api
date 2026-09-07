<?php

namespace App\Models;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\GoalMetrics;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialGoal extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'category',
        'target_amount',
        'target_date',
        'saved_amount',
        'note',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'saved_amount' => 'decimal:2',
            'target_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Derived figures come from FinancialEngine, so a goal shown on Home, on
     * Goals and inside a future recommendation always agrees with itself.
     */
    public function metrics(): GoalMetrics
    {
        return app(FinancialEngine::class)->goalMetrics($this);
    }

    /** 0..1, clamped — a goal that is overfunded still reads as complete. */
    public function progress(): float
    {
        return $this->metrics()->progress();
    }

    public function monthsRemaining(): int
    {
        return $this->metrics()->monthsRemaining;
    }

    /** What the customer must set aside each month to land on target. */
    public function monthlyRequired(): float
    {
        return (float) $this->metrics()->monthlyRequired->toJsonNumber();
    }

    /** max(0, target - saved) */
    public function remainingAmount(): float
    {
        return (float) $this->metrics()->remaining->toJsonNumber();
    }
}
