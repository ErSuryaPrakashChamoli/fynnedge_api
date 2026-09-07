<?php

namespace App\Domain\Catalog;

use InvalidArgumentException;

/**
 * Who offers a product.
 *
 * Identity is the code, never the display name. A provider that rebrands
 * keeps every product, every application snapshot and every reference it
 * ever had; only what the customer reads changes.
 */
final class Provider
{
    public function __construct(
        /** Stable internal identity. Lowercase, never shown to a customer. */
        public readonly string $code,

        /** What the customer reads. Free to change. */
        public readonly string $name,

        public readonly ProviderType $type,

        /**
         * An invented provider, used to demonstrate the product.
         *
         * Held on the provider as well as on each product's source because
         * the two answer different questions: this one is "does this
         * organisation exist", the other is "where did this rate come from".
         * A sample provider cannot have live products.
         */
        public readonly bool $isSample = true,
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code)) {
            throw new InvalidArgumentException(
                "Provider code '{$code}' must be lowercase alphanumeric with "
                .'underscores, 2–40 characters.',
            );
        }

        if (trim($name) === '') {
            throw new InvalidArgumentException(
                "Provider '{$code}' has no display name.",
            );
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'is_sample' => $this->isSample,
        ];
    }
}
