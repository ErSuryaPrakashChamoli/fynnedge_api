<?php

namespace App\Domain\Credit;

/**
 * Which bureau supplied a report.
 *
 * A bureau is a provider, not the architecture. FynnEdge's domain speaks of
 * a credit report and a score model; whether that came from TransUnion
 * CIBIL, Experian, Equifax or CRIF is a fact about the record, not a shape
 * the rest of the system has to know.
 */
enum CreditBureau: string
{
    /** A fictional bureau. NO LIVE BUREAU IS CONNECTED TO FYNNEDGE. */
    case Fixture = 'fixture';

    public function isLive(): bool
    {
        return $this !== self::Fixture;
    }

    public function label(): string
    {
        return match ($this) {
            self::Fixture => 'Sample bureau',
        };
    }
}
