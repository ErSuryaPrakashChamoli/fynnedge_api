<?php

namespace App\Domain\Provider;

/**
 * Turns one provider's row into FynnEdge's own terms.
 *
 * This is where every difference between providers is absorbed: their field
 * names, their idea of what `11.5` means, whether they count tenure in
 * months or years, what they call a personal loan.
 *
 * A normalizer may rename, convert units and map categories. It may not
 * decide anything about a customer — no affordability, no score, no verdict
 * on eligibility. It reads a product and describes it.
 *
 * When a value cannot be interpreted without guessing, the row is rejected.
 * A guessed interest rate is worse than a missing product.
 */
interface ProviderNormalizer
{
    /**
     * @param  array<string, mixed>  $row
     *
     * @throws NormalizationFailed when the row cannot be read unambiguously
     */
    public function normalize(array $row): CanonicalProductInput;
}
