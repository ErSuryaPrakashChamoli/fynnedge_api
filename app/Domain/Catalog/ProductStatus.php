<?php

namespace App\Domain\Catalog;

/**
 * Where a product sits in its life.
 *
 * Three states, because three is what the catalogue can distinguish today.
 * Nothing is ever deleted: an application made against a product is a record
 * of what the customer agreed to, and it has to stay readable long after the
 * product stops being offered.
 */
enum ProductStatus: string
{
    /** Being prepared. Never reaches a customer. */
    case Draft = 'draft';

    /** Offered: appears in discovery and can be applied for. */
    case Active = 'active';

    /**
     * No longer offered. Excluded from discovery, and every application
     * already made against it stays exactly as it was.
     */
    case Inactive = 'inactive';

    /** Whether a customer may be shown this product as something to choose. */
    public function isDiscoverable(): bool
    {
        return $this === self::Active;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
