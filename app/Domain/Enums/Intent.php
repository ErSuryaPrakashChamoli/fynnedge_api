<?php

namespace App\Domain\Enums;

/** Screen 6 — what brought the customer to FynnEdge. */
enum Intent: string
{
    case NeedLoan = 'need_loan';
    case ReduceEmi = 'reduce_emi';
    case CompareOffers = 'compare_offers';
    case CheckHealth = 'check_health';
    case PlanGoal = 'plan_goal';
    case Exploring = 'exploring';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
