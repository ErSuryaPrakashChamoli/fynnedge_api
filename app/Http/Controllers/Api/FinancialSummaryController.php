<?php

namespace App\Http\Controllers\Api;

use App\Domain\Financial\FinancialEngine;
use App\Http\Controllers\Controller;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /financial-summary — the whole engine result for the authenticated
 * customer, with the formulas that produced it.
 *
 * This exists because nothing else exposes the *aggregate* goal figures (what
 * every goal costs together each month, total remaining) or the definitions,
 * and the modules that come next need both. Per-goal and per-profile figures
 * remain on their existing endpoints; this does not replace them.
 */
class FinancialSummaryController extends Controller
{
    public function __construct(
        private readonly FinancialEngine $engine,
        private readonly CustomerService $customers,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Always the caller's own data; there is no id to pass in.
        $profile = $this->customers->financialProfileFor($user);
        $goals = $user->goals()
            ->where('status', 'active')
            ->orderBy('target_date')
            ->get();

        return response()->json([
            'data' => $this->engine->snapshot($profile, $goals)->toArray(),
        ]);
    }
}
