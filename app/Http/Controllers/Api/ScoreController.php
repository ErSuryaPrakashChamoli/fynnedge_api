<?php

namespace App\Http\Controllers\Api;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Score\FynnScoreEngine;
use App\Http\Controllers\Controller;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /fynn-score — the authenticated customer's FynnScore.
 *
 * Goals are not passed to the scoring engine: FynnScore v1 is deliberately
 * time-independent, and goal metrics move with the calendar.
 */
class ScoreController extends Controller
{
    public function __construct(
        private readonly FinancialEngine $financial,
        private readonly FynnScoreEngine $scoring,
        private readonly CustomerService $customers,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $profile = $this->customers->financialProfileFor($request->user());

        $snapshot = $this->financial->snapshot($profile);

        return response()->json([
            'data' => $this->scoring->score(
                $snapshot,
                $profile->updated_at?->toIso8601String(),
            )->toArray(),
        ]);
    }
}
