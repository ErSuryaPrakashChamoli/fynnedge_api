<?php

namespace App\Domain\Intelligence;

/**
 * The kinds of next step FynnEdge can suggest.
 *
 * Every one is something the customer can actually do in the app today.
 *
 * There is no `apply_now`, no `take_this_loan` and no `refinance`. Those
 * are decisions FynnEdge does not make for people, and a planner that made
 * them would be a sales funnel wearing a coach's clothes.
 *
 * `improve_score` is not a promise that the score will improve. It opens
 * the screen showing which component is furthest from its reference and
 * what the engine calculates the component would score at that reference —
 * a hypothetical the engine already computes, not a prediction.
 */
enum NextActionType: string
{
    case ResolveSubmissionStatus = 'resolve_submission_status';
    case ReviewApplication = 'review_application';
    case CompleteProfile = 'complete_profile';
    case AddYourName = 'add_your_name';
    case ReviewGoal = 'review_goal';
    case ExploreOptions = 'explore_options';
    case CreateGoal = 'create_goal';
    case ReviewScore = 'review_score';

    /*
     * Added in Module 33, when the single next action became a short plan.
     *
     * Each has a real destination inside FynnEdge and a canonical source
     * behind it: a score recommendation the engine already produces, a
     * decision the customer actually recorded, and the emergency-fund
     * reference FinancialEngine already uses. None is generic advice.
     */
    case ReviewProfile = 'review_profile';
    case ImproveScore = 'improve_score';
    case ReviewDecision = 'review_decision';
    case ReviewBuffer = 'review_buffer';
    case RunSimulation = 'run_simulation';
}
