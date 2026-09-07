<?php

namespace App\Domain\Enums;

/**
 * Mirrors the Flutter EmploymentType enum. The ids are the contract; the
 * labels are the app's business, not ours.
 */
enum EmploymentType: string
{
    case Salaried = 'salaried';
    case SelfEmployed = 'self_employed';
    case Business = 'business';
    case Professional = 'professional';
    case Other = 'other';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Lenders price self-employed income differently from salaried. */
    public function isSelfEmployed(): bool
    {
        return in_array($this, [self::SelfEmployed, self::Business], true);
    }
}
