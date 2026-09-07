<?php

namespace App\Domain\Twin;

/**
 * The decisions FynnTwin can simulate.
 *
 * Three, because three can be calculated from what FynnEdge already knows
 * without assuming anything about the customer's future. An income-change
 * scenario is absent on purpose: modelling a raise means assuming when it
 * arrives and whether it lasts, and neither is something the customer has
 * told us.
 */
enum ScenarioType: string
{
    /** Taking a loan from the catalogue. */
    case Loan = 'loan';

    /** Spending more, or less, each month. */
    case ExpenseChange = 'expense_change';

    /** Setting money aside each month for a stated number of months. */
    case SavingChange = 'saving_change';

    public function label(): string
    {
        return match ($this) {
            self::Loan => 'Take a loan',
            self::ExpenseChange => 'Change my monthly spending',
            self::SavingChange => 'Set money aside each month',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Loan => 'See what a loan from the catalogue would do to '
                .'your month before you apply for one.',
            self::ExpenseChange => 'See what spending more — or less — each '
                .'month would do to what you have left over.',
            self::SavingChange => 'See how far a monthly amount set aside '
                .'would take your savings, and what that covers.',
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
