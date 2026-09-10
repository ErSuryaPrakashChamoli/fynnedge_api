<?php

namespace App\Http\Controllers\Api;

use App\Domain\Intelligence\FinancialState;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\NextBestAction;
use App\Domain\Intelligence\ObservedChange;
use App\Http\Controllers\Controller;
use App\Models\FinancialGoal;
use App\Models\FinancialMoment;
use App\Services\Intelligence\FinancialIntelligence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What FynnEdge understands about the customer's money.
 *
 * One endpoint rather than four. `insights`, `next_action` and
 * `recent_moments` all describe the same customer at the same instant, and
 * splitting them across requests means a client can assemble a screen from
 * three views of a person taken at three different times — with the next
 * action contradicting the insight beside it.
 *
 * The timeline is separate, because it is a different question: not "what
 * is true now" but "what has happened", and it is read on its own screen.
 *
 * NO LANGUAGE MODEL, PREDICTIVE MODEL OR ANALYTICS PLATFORM IS INVOLVED.
 * Every figure here came from FinancialEngine, FynnScoreEngine or a goal's
 * own metrics, and every insight is a comparison against one of them.
 */
class IntelligenceController extends Controller
{
    public function __construct(private readonly FinancialIntelligence $intelligence) {}

    /**
     * GET /intelligence
     *
     * The state read once and handed to both rule sets, so the insight and
     * the next action cannot disagree about the customer.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $state = $this->intelligence->stateFor($user);

        return response()->json([
            'data' => [
                /*
                 * Where the customer stands.
                 *
                 * The plan's factual half, from the snapshot this request
                 * already read — the same figures FinancialEngine gave the
                 * ladder below it, so the header and the suggestion cannot
                 * describe two different customers. Nothing here is stored,
                 * and there is no /financial-plan: a second request would
                 * be a second instant, and a plan that disagreed with the
                 * action beside it is the failure this endpoint's shape
                 * exists to prevent.
                 */
                'position' => $this->intelligence->positionFor($state)->toArray(),

                /*
                 * The score, from the state this request already read.
                 *
                 * Not a second scoring path: FynnScoreEngine ran once when
                 * the state was built, and this is that result. A screen
                 * that fetched /fynn-score alongside this endpoint would be
                 * describing the same customer at two instants, and could
                 * show a band that disagreed with the position above it.
                 *
                 * A summary rather than the whole score. The dimensions,
                 * the recommendations and the curves are what the FynnScore
                 * screen exists for.
                 */
                'score' => $state->score->toSummaryArray(),

                /*
                 * The goals, from the same read.
                 *
                 * Every figure was derived by FinancialEngine when it built
                 * the snapshot above — nothing here recomputes a progress,
                 * a monthly amount or a months-remaining. Ordered as the
                 * Goals module orders them, nearest target date first, and
                 * no goal is singled out as the important one.
                 */
                'goals' => $this->goals($state),

                'next_action' => $this->intelligence->nextActionFor($state)?->toArray(),

                /*
                 * The same reasoning, as a short plan.
                 *
                 * Carried here rather than on an endpoint of its own: it
                 * is the next action extended from one to three, built
                 * from the same single read of the same state. A separate
                 * request would describe the same customer at a different
                 * instant and could contradict the field above it.
                 *
                 * Capped at three, and allowed to be short or empty — a
                 * customer with nothing pending is told so rather than
                 * handed an invented task.
                 */
                'plan' => array_map(
                    fn (NextBestAction $a) => $a->toArray(),
                    $this->intelligence->planFor($state),
                ),

                'insights' => array_map(
                    fn (Insight $i) => $i->toArray(),
                    $this->intelligence->insightsFor($state),
                ),

                'recent_moments' => $this->moments(
                    $user,
                    FinancialIntelligence::MAX_RECENT_MOMENTS,
                ),

                /*
                 * Said plainly rather than implied by an empty array.
                 *
                 * FynnEdge kept no financial history before Module 31, so a
                 * customer who has changed nothing since has none — and the
                 * screen needs to be able to say "not enough history yet"
                 * instead of showing a blank where a trend would go.
                 */
                'history' => [
                    'observations' => $state->observationCount,
                    'has_comparable_history' => $state->canShowTrends(),

                    /*
                     * What actually changed, between two things FynnEdge
                     * wrote down.
                     *
                     * Never today's figures against a stored row: the
                     * customer's current state is what is true now, not
                     * something that was observed, and pairing the two
                     * would make every entry half record and half reading.
                     *
                     * Empty is the ordinary answer, and the field above
                     * says why rather than leaving a blank to interpret.
                     */
                    'items' => array_map(
                        fn (ObservedChange $c) => $c->toArray(),
                        $this->intelligence->progressFor($user)->changes(),
                    ),
                ],
            ],
        ]);
    }

    /**
     * The customer's goals as anything reading this state needs them.
     *
     * Built from the metrics FinancialEngine already produced for the
     * snapshot, matched back to their models for the fields a customer
     * reads. Bounded, with the true count beside it so a short list never
     * reads as the whole of somebody's goals.
     *
     * @return array<string, mixed>
     */
    private function goals(FinancialState $state): array
    {
        $models = $state->goals->keyBy(fn (FinancialGoal $g) => (string) $g->getKey());

        $items = [];

        foreach ($state->snapshot->goals as $metrics) {
            if (count($items) === FinancialIntelligence::MAX_GOALS) {
                break;
            }

            $goal = $models->get($metrics->id);

            $items[] = $metrics->toArray() + [
                'category' => $goal?->category,
                'target_date' => $goal?->target_date?->toIso8601String(),
            ];
        }

        return [
            'count' => $state->goals->count(),
            'items' => $items,
        ];
    }

    /**
     * GET /intelligence/timeline
     *
     * The customer's financial story, newest first. Not the audit log:
     * nothing here is a webhook, a retry, a storage operation or a consent
     * record.
     */
    public function timeline(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.FinancialIntelligence::MAX_MOMENTS],
        ]);

        return response()->json([
            'data' => $this->moments(
                $request->user(),
                (int) ($validated['limit'] ?? FinancialIntelligence::MAX_MOMENTS),
            ),
        ]);
    }

    /**
     * Moments as the customer sees them.
     *
     * Never the rule, the evidence or the idempotency key: those say why
     * FynnEdge recorded it and describe the shape of the system.
     *
     * @return list<array<string, mixed>>
     */
    private function moments($user, int $limit): array
    {
        return $this->intelligence->momentsFor($user, $limit)
            ->map(fn (FinancialMoment $m) => array_filter([
                'id' => (string) $m->id,
                'type' => $m->type,
                'importance' => $m->importance,
                'title' => $m->title,
                'description' => $m->description,
                'context_type' => $m->context_type,
                'context_reference' => $m->context_reference,
                'occurred_at' => $m->occurred_at?->toIso8601String(),
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }
}
