<?php

namespace App\Domain\Intelligence;

/**
 * How much weight a moment carries on a timeline.
 *
 * Three levels, used for emphasis only — a major moment gets a brighter
 * dot on the timeline. Nothing in FynnEdge decides anything from this: it
 * does not affect eligibility, pricing or a score, and it never becomes a
 * notification on its own. Module 29 decides that separately, from the
 * event rather than from how a timeline chose to draw it.
 */
enum MomentImportance: string
{
    case Ordinary = 'ordinary';
    case Notable = 'notable';
    case Major = 'major';
}
