<?php

namespace App\Domain\Intelligence;

/**
 * The kinds of thing worth remembering about a customer's money.
 *
 * Every case here is derivable from something FynnEdge already holds
 * authoritatively. There is no `income_increased`, because a customer's
 * previous income was destroyed by the edit that changed it until Module 31
 * began observing; no `likely_to_be_approved`, because nothing computes
 * that; and no `spending_improving`, because two observations is not a
 * trend and FynnEdge rarely has two.
 *
 * Kept deliberately short. A catalogue of forty moment types is a timeline
 * nobody reads, and most of them would be events wearing a customer-facing
 * label.
 */
enum FinancialMomentType: string
{
    // --- Financial picture -------------------------------------------------

    /** Enough was entered for FynnEdge to work anything out at all. */
    case ProfileCompleted = 'profile_completed';

    /** A FynnScore existed for the first time. Not a change — an arrival. */
    case ScoreAvailable = 'score_available';

    /** A score moved enough to mean something, against an observed one. */
    case ScoreImproved = 'score_improved';
    case ScoreDeclined = 'score_declined';

    // --- Goals -------------------------------------------------------------

    case GoalCreated = 'goal_created';
    case GoalMilestone = 'goal_milestone';
    case GoalReached = 'goal_reached';

    // --- Decisions ---------------------------------------------------------

    case ApplicationStarted = 'application_started';
    case ApplicationSubmitted = 'application_submitted';

    /** Only ever from a real provider event. Never inferred. */
    case ApplicationDecided = 'application_decided';
    case ApplicationDisbursed = 'application_disbursed';
    case ApplicationCancelled = 'application_cancelled';

    /**
     * Whether a provider — not FynnEdge — is the author of this.
     *
     * Used to keep the timeline honest about who did what, and to make sure
     * nothing here can be created without a provider event behind it.
     */
    public function comesFromProvider(): bool
    {
        return in_array($this, [
            self::ApplicationDecided,
            self::ApplicationDisbursed,
        ], true);
    }

    public function importance(): MomentImportance
    {
        return match ($this) {
            self::ApplicationDecided,
            self::ApplicationDisbursed => MomentImportance::Major,

            self::ScoreAvailable,
            self::GoalReached,
            self::ApplicationSubmitted => MomentImportance::Notable,

            default => MomentImportance::Ordinary,
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
