<?php

namespace App\Domain\Vault;

/**
 * The document kinds FynnVault already represents.
 *
 * Deliberately the same six the app has always shown. A larger taxonomy
 * would be inventing filing requirements no part of the product asks for.
 */
enum DocumentType: string
{
    case Identity = 'identity';
    case Income = 'income';
    case Bank = 'bank';
    case Loan = 'loan';
    case Property = 'property';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Identity => 'Identity',
            self::Income => 'Income',
            self::Bank => 'Bank',
            self::Loan => 'Loan',
            self::Property => 'Property',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
