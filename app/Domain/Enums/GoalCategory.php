<?php

namespace App\Domain\Enums;

/** Screen 7 — what the borrowing or planning is for. */
enum GoalCategory: string
{
    case Business = 'business';
    case Home = 'home';
    case Car = 'car';
    case Education = 'education';
    case Medical = 'medical';
    case Debt = 'debt';
    case Personal = 'personal';
    case Other = 'other';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
