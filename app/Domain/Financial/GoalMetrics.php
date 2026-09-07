<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * Everything derived about a single goal.
 */
final class GoalMetrics
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly Money $target,
        public readonly Money $saved,
        public readonly Money $remaining,
        /** 0..1 to four decimals, held as ten-thousandths. */
        public readonly int $progressTenThousandths,
        public readonly int $monthsRemaining,
        public readonly Money $monthlyRequired,
        public readonly bool $isComplete,
        public readonly bool $isOverdue,
    ) {}

    public function progress(): float
    {
        return round($this->progressTenThousandths / 10000, 4);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'target_amount' => $this->target->toJsonNumber(),
            'saved_amount' => $this->saved->toJsonNumber(),
            'remaining_amount' => $this->remaining->toJsonNumber(),
            'progress' => $this->progress(),
            'months_remaining' => $this->monthsRemaining,
            'monthly_required' => $this->monthlyRequired->toJsonNumber(),
            'is_complete' => $this->isComplete,
            'is_overdue' => $this->isOverdue,
        ];
    }
}
