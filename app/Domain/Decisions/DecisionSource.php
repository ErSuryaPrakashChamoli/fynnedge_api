<?php

namespace App\Domain\Decisions;

/**
 * Where a number in an explanation came from.
 *
 * The most important field in the module. A customer reading "your EMI
 * would be ₹22,881" is entitled to know that FynnEdge worked it out, and a
 * customer reading "13.25%" is entitled to know that a lender published it
 * and FynnEdge did not invent it.
 *
 * There is deliberately no `estimated`, no `predicted` and no `inferred`.
 * FynnEdge does not estimate: it calculates from figures it holds, or it
 * says it does not know.
 */
enum DecisionSource: string
{
    /** The customer typed it. */
    case CustomerProvided = 'customer_provided';

    /** FynnEdge worked it out, from figures it holds. */
    case Calculated = 'calculated';

    /** A lender published it in the catalogue. */
    case CataloguePublished = 'catalogue_published';

    /** A provider said it. Only ever from a real provider event. */
    case ProviderDerived = 'provider_derived';

    /** A what-if. True of nothing that has happened. */
    case Simulated = 'simulated';

    /** What was true when the decision was recorded, not now. */
    case HistoricalSnapshot = 'historical_snapshot';

    public function label(): string
    {
        return match ($this) {
            self::CustomerProvided => 'You told us',
            self::Calculated => 'FynnEdge worked this out',
            self::CataloguePublished => 'Published by the lender',
            self::ProviderDerived => 'The provider told us',
            self::Simulated => 'Simulated',
            self::HistoricalSnapshot => 'As it was at the time',
        };
    }
}
