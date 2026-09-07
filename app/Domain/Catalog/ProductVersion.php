<?php

namespace App\Domain\Catalog;

use App\Domain\Money;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * What a product's terms were, over a period of time.
 *
 * Everything that can change while a product stays the same product lives
 * here: pricing, the eligibility a provider publishes, and the copy that
 * explains it. The product's identity does not, so a rate change is a new
 * version rather than a different product.
 *
 * A version is closed at both ends or open at the far one. Two versions of
 * the same product may not both be in force on the same day — see
 * CatalogueProduct::versionOn, which refuses to choose.
 */
final class ProductVersion
{
    /**
     * @param  list<string>  $suitedReasons
     * @param  list<string>  $considerations
     * @param  list<string>  $conditions
     * @param  array<string, array{label: string, value: int, explanation: string}>  $trustBreakdown
     */
    public function __construct(
        /**
         * The persisted row's id, when this version came from the database.
         *
         * Identity only, so an application can record which version it was
         * created from. Null for the sample catalogue, which has no rows.
         */
        public readonly ?int $persistedId,

        public readonly CarbonInterface $effectiveFrom,

        /** Null means still in force. */
        public readonly ?CarbonInterface $effectiveUntil,

        // --- Pricing -----------------------------------------------------
        public readonly float $interestRate,
        public readonly float $processingFeePercent,
        public readonly ?Money $processingFeeCap,
        public readonly string $prepaymentCharge,

        // --- What the product will lend --------------------------------
        public readonly Money $minAmount,
        public readonly Money $maxAmount,
        public readonly int $minTenureMonths,
        public readonly int $maxTenureMonths,

        // --- Eligibility the provider publishes -------------------------
        // Null is "not published", never "no requirement". FynnMatch reports
        // an unpublished rule as unchecked rather than passed.
        public readonly ?Money $minMonthlyIncome,
        public readonly ?int $minAge,
        public readonly ?int $maxAgeAtMaturity,

        // --- FynnEdge's published evaluation ----------------------------
        public readonly int $fynnTrust,
        public readonly array $trustBreakdown,

        // --- Copy. Explains; never decides. ------------------------------
        public readonly string $lenderTag,
        public readonly array $suitedReasons,
        public readonly array $considerations,
        public readonly array $conditions,

        public readonly ProductSource $source,

        /**
         * Documented minimum credit score, or null when the product states
         * none — which is the ordinary case, and is never read as "no
         * minimum". A product that says nothing about credit needs no credit
         * check to match.
         */
        public readonly ?int $minCreditScore = null,
    ) {
        if ($effectiveUntil !== null
            && $effectiveUntil->lessThanOrEqualTo($effectiveFrom)) {
            throw new InvalidArgumentException(
                'A product version cannot end before it begins.',
            );
        }
    }

    /** Whether this version was in force on the given day. */
    public function coversDate(CarbonInterface $at): bool
    {
        if ($at->lessThan($this->effectiveFrom)) {
            return false;
        }

        return $this->effectiveUntil === null
            || $at->lessThan($this->effectiveUntil);
    }

    /** Whether this version and another are ever in force together. */
    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = $other->effectiveUntil === null
            || $this->effectiveFrom->lessThan($other->effectiveUntil);

        $endsAfterOtherStarts = $this->effectiveUntil === null
            || $this->effectiveUntil->greaterThan($other->effectiveFrom);

        return $startsBeforeOtherEnds && $endsAfterOtherStarts;
    }
}
