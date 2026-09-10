<?php

namespace App\Http\Controllers\Api;

use App\Domain\Decisions\DecisionEntry;
use App\Domain\Decisions\Explanation;
use App\Http\Controllers\Controller;
use App\Services\Decisions\DecisionHistory;
use App\Services\Decisions\ExplanationBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the customer explored, and why FynnEdge showed what it did.
 *
 * Read-only, entirely. There is no endpoint that creates a decision:
 * a decision is a record of something the customer did, and an endpoint
 * that could write one would let a client write history. The two that
 * record — a simulation and a comparison — do so from the customer's own
 * action, in the endpoint that action reaches.
 *
 * Every entry resolves through `$request->user()`, and an id belonging to
 * somebody else is not found rather than refused.
 *
 * NO LANGUAGE MODEL OR PREDICTIVE MODEL IS INVOLVED. Every explanation is
 * assembled from figures recorded at the time by the engines that produced
 * them.
 */
class DecisionController extends Controller
{
    public function __construct(
        private readonly DecisionHistory $history,
        private readonly ExplanationBuilder $explanations,
    ) {}

    /**
     * GET /decisions
     *
     * The history, newest first. Empty for a customer who has explored
     * nothing — and empty is the honest answer, not a prompt to go and do
     * something.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.DecisionHistory::MAX_ENTRIES],
        ]);

        $entries = $this->history->for(
            $request->user(),
            (int) ($validated['limit'] ?? DecisionHistory::MAX_ENTRIES),
        );

        return response()->json([
            'data' => array_map(fn (DecisionEntry $e) => $e->toArray(), $entries),
        ]);
    }

    /**
     * GET /decisions/{decision}
     *
     * One entry with its explanation. The explanation is built from what
     * was recorded at the time, so a decision from six months ago explains
     * itself with the rate it was actually shown.
     */
    public function show(Request $request, string $decision): JsonResponse
    {
        $entry = $this->history->find($request->user(), $decision);

        abort_if($entry === null, Response::HTTP_NOT_FOUND, 'Decision not found.');

        return response()->json([
            'data' => [
                ...$entry->toArray(),

                /*
                 * Said explicitly on every entry.
                 *
                 * A customer looking at a six-month-old decision needs to
                 * know they are looking at what was true then, not at
                 * anything FynnEdge is claiming now.
                 */
                'status' => 'historical',

                'explanation' => array_map(
                    fn (Explanation $e) => $e->toArray(),
                    $this->explanations->for($entry),
                ),
            ],
        ]);
    }
}
