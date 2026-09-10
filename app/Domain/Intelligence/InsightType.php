<?php

namespace App\Domain\Intelligence;

/**
 * The small set of things FynnEdge can tell a customer about their money.
 *
 * Each has a use, and each is derived from a figure an engine produced.
 * There is no `tip_of_the_day`, no `encouragement` and no generic
 * `ai_insight`: a message that says "keep going!" carries nothing a
 * customer can act on, and a category called AI would be a category
 * anything could be dropped into.
 */
enum InsightType: string
{
    /*
     * There is no `score_change` here.
     *
     * A score moving is something that happened, which makes it a moment
     * rather than an insight — and insights are derived from current state
     * on every read, so a score change would either be recomputed against
     * the wrong baseline or duplicate the moment already recording it.
     * It appears in the timeline, once, where it belongs.
     */
    case ProfileIncomplete = 'profile_incomplete';
    case FinancialHealth = 'financial_health';
    case GoalDeadline = 'goal_deadline';
    case GoalProgress = 'goal_progress';
    case ApplicationAttention = 'application_attention';
    case ApplicationUpdate = 'application_update';

    /**
     * Where this sits in the order, lowest first.
     *
     * Ordered by what it costs the customer to ignore it, not by when it
     * was produced. Something blocking them comes before something they
     * cannot act on today, and a goal deadline comes before an observation
     * about their ratios.
     */
    public function priority(): int
    {
        return match ($this) {
            self::ApplicationAttention => 1,
            self::ProfileIncomplete => 2,
            self::ApplicationUpdate => 3,
            self::GoalDeadline => 4,
            self::GoalProgress => 5,
            self::FinancialHealth => 6,
        };
    }
}
