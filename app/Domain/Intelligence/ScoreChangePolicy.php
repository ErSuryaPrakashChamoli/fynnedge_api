<?php

namespace App\Domain\Intelligence;

/**
 * How far a FynnScore must move before FynnEdge mentions it.
 *
 * Three points, and the reasoning matters more than the number.
 *
 * FynnScore is a whole number from 0 to 100 built from three weighted
 * components. One point is inside the rounding of a trivial edit — adding a
 * few hundred rupees of savings moves it — and a product that announced
 * that would teach the customer to ignore the announcements that matter.
 * Three is the smallest move that survives rounding at both ends and still
 * reflects a real change in one of the three components.
 *
 * Deliberately not a band change. Module 29 uses the band to decide whether
 * to *interrupt* the customer with a notification, which is a higher bar
 * than whether to mention something on a screen they chose to open. Two
 * different questions, two different thresholds, both written down.
 */
final class ScoreChangePolicy
{
    public const POINTS = 3;

    /** Whether a movement of this size is worth telling the customer about. */
    public static function isMeaningful(int $delta): bool
    {
        return abs($delta) >= self::POINTS;
    }
}
