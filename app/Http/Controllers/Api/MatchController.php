<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\ProductRepository;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Match\FynnMatchEngine;
use App\Domain\Match\MatchRequest;
use App\Domain\Match\ProductMatch;
use App\Domain\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\FynnMatchRequest;
use App\Services\Credit\CreditBureauService;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;

/**
 * GET /fynn-match — which catalogue products fit this customer's request.
 *
 * The customer is the authenticated one, always. Amount, tenure and purpose
 * come from the query because they are the customer's stated need; whose
 * finances are evaluated never does.
 */
class MatchController extends Controller
{
    public function __construct(
        private readonly FynnMatchEngine $matching,
        private readonly FinancialEngine $financial,
        private readonly CustomerService $customers,
        private readonly ProductRepository $products,
        private readonly CreditBureauService $credit,
    ) {}

    public function index(FynnMatchRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->customers->financialProfileFor($user);
        $snapshot = $this->financial->snapshot($profile);

        $matchRequest = new MatchRequest(
            amount: Money::of($request->validated('amount')),
            tenureMonths: (int) $request->validated('tenure_months'),
            purpose: $request->validated('purpose'),
            preferredEmi: $request->filled('preferred_emi')
                ? Money::of($request->validated('preferred_emi'))
                : null,
        );

        $matches = $this->matching->match(
            $matchRequest,
            $snapshot,
            $user->customerProfile?->age,
            // Whatever credit information FynnEdge already holds. Reading it
            // never triggers a bureau request: a check happens only when the
            // customer asks for one, because a pull may cost money and be
            // recorded as an enquiry against them.
            $this->credit->profile($user),
        );

        $viable = array_values(array_filter(
            $matches,
            fn (ProductMatch $m) => $m->category->isViable(),
        ));

        return response()->json([
            'data' => [
                'request' => $matchRequest->toArray(),

                // Everything evaluated, viable first. The app shows matches;
                // the rest are here so it can explain what was ruled out.
                'matches' => array_map(
                    fn (ProductMatch $m) => $m->toArray(),
                    $matches,
                ),
                'match_count' => count($viable),
                'evaluated_count' => count($matches),

                'customer_metrics' => [
                    'monthly_income' => $snapshot->income->toJsonNumber(),
                    'existing_emi' => $snapshot->existingEmi->toJsonNumber(),
                    'emi_ratio' => $snapshot->emiRatio->toJsonNumber(),
                    'surplus' => $snapshot->surplus->toJsonNumber(),
                ],
                'has_financial_profile' => $snapshot->income->isPositive(),
                'affordability_ceiling_percent' => FynnMatchEngine::AFFORDABILITY_CEILING_PERCENT,

                // The customer's position with no new loan on it: current
                // ratio, the ceiling, and what is still under it. Same
                // engine call as a product projection, with a zero EMI.
                'affordability' => $this->financial
                    ->project($snapshot, Money::zero())
                    ->toArray(),

                'is_sample_catalogue' => $this->products->isSampleData(),
                'disclaimer' => FynnMatchEngine::DISCLAIMER,
            ],
        ]);
    }

    /**
     * GET /fynn-match/{product} — one product at a chosen amount and tenure.
     *
     * This is the simulation behind Loan Detail's sliders. It computes and
     * returns; it writes nothing. Moving a slider must never change what
     * FynnEdge holds about the customer.
     */
    public function show(FynnMatchRequest $request, string $product): JsonResponse
    {
        $found = $this->products->find($product);

        if ($found === null) {
            return response()->json([
                'message' => 'That product is no longer in our set.',
            ], 404);
        }

        $user = $request->user();
        $snapshot = $this->financial->snapshot(
            $this->customers->financialProfileFor($user)
        );

        $match = $this->matching->evaluate(
            $found,
            new MatchRequest(
                amount: Money::of($request->validated('amount')),
                tenureMonths: (int) $request->validated('tenure_months'),
                purpose: $request->validated('purpose'),
            ),
            $snapshot,
            $user->customerProfile?->age,
            // The same credit information the list was evaluated against.
            // Detail contradicting Explore about the same requirement would
            // be worse than neither of them mentioning it.
            $this->credit->profile($user),
        );

        return response()->json([
            'data' => array_merge($match->toArray(), [
                'is_sample_data' => $this->products->isSampleData(),
                'disclaimer' => FynnMatchEngine::DISCLAIMER,
            ]),
        ]);
    }
}
