<?php

namespace App\Domain\Provider;

/**
 * A provider requirement FynnEdge cannot currently evaluate.
 *
 * A provider may publish a minimum credit score, a business vintage or a
 * banking history requirement. FynnEdge holds none of those, so it cannot
 * say whether a customer meets them.
 *
 * The requirement is kept rather than dropped, because dropping it would
 * leave a product looking fully checked when it is not. It is kept
 * *separately* from the eligibility FynnMatch evaluates, because folding it
 * in would make FynnMatch report a verdict on something it never looked at.
 * Neither pass nor fail — outside what FynnEdge can see.
 */
final class UnsupportedRequirement
{
    public function __construct(
        /** The provider's own name for it, e.g. `minimum_cibil_score`. */
        public readonly string $key,

        /** Their stated value, kept verbatim as text. */
        public readonly string $value,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'value' => $this->value];
    }
}
