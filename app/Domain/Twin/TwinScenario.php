<?php

namespace App\Domain\Twin;

use App\Domain\Money;

/**
 * What the customer chose to simulate.
 *
 * Assumptions only. The customer's current position is loaded from their
 * profile on the server and never travels in here, so a scenario cannot
 * quietly redefine where they are starting from.
 */
final class TwinScenario
{
    public function __construct(
        public readonly ScenarioType $type,

        /** Loan: what to borrow. */
        public readonly ?Money $amount = null,
        public readonly ?int $tenureMonths = null,
        public readonly ?string $productId = null,

        /** Expense change: the monthly difference, positive or negative. */
        public readonly ?Money $monthlyChange = null,

        /** Saving: the monthly amount, and how many months of it. */
        public readonly ?Money $monthlyAmount = null,
        public readonly ?int $months = null,
    ) {}

    /**
     * The assumptions, in the customer's own terms, so a result can always
     * be read back against what was asked.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'amount' => $this->amount?->toJsonNumber(),
            'tenure_months' => $this->tenureMonths,
            'product_id' => $this->productId,
            'monthly_change' => $this->monthlyChange?->toJsonNumber(),
            'monthly_amount' => $this->monthlyAmount?->toJsonNumber(),
            'months' => $this->months,
        ], fn ($value) => $value !== null);
    }
}
