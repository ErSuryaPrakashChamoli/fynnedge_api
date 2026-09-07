<?php

namespace App\Domain\Catalog;

/**
 * What kind of organisation a provider is.
 *
 * Only what the sample catalogue actually contains. A classification nobody
 * uses is a field a future integration has to fill in for nothing.
 */
enum ProviderType: string
{
    case Bank = 'bank';
    case Nbfc = 'nbfc';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
