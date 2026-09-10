<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\ProductRepository;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Money;
use App\Domain\Twin\FynnTwinEngine;
use App\Domain\Twin\ScenarioType;
use App\Domain\Twin\TwinScenario;
use App\Http\Controllers\Controller;
use App\Http\Requests\SimulateTwinRequest;
use App\Services\CustomerService;
use App\Services\Decisions\CustomerDecisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FynnTwin — simulating a decision.
 *
 * Stateless. A simulation writes nothing: no scenario table, no application,
 * no change to the customer's profile. Asking what a loan would do is not
 * the same as taking one, and the API keeps that difference.
 */
class FynnTwinController extends Controller
{
    public function __construct(
        private readonly FynnTwinEngine $twin,
        private readonly FinancialEngine $financial,
        private readonly CustomerService $customers,
        private readonly CustomerDecisions $decisions,
        private readonly ProductRepository $products,
    ) {}

    /** What FynnTwin can simulate, and whether this customer can use it. */
    public function index(Request $request): JsonResponse
    {
        $snapshot = $this->currentState($request);

        return response()->json([
            'data' => [
                'scenarios' => array_map(fn (ScenarioType $type) => [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                ], ScenarioType::cases()),

                // Without an income there is nothing to project against, and
                // the app is told so rather than shown an empty simulation.
                'has_financial_profile' => $snapshot->hasIncome,
                'affordability_reference_percent' => FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
            ],
        ]);
    }

    public function simulate(SimulateTwinRequest $request): JsonResponse
    {
        $type = ScenarioType::from($request->validated('type'));
        $product = null;

        if ($type === ScenarioType::Loan) {
            $product = $this->products->find($request->validated('product_id'));

            if ($product === null) {
                return response()->json([
                    'message' => 'That product is not in the catalogue.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // The starting point is the authenticated customer's, always.
        $current = $this->currentState($request);

        $projection = $this->twin->simulate(
            $current,
            new TwinScenario(
                type: $type,
                amount: $this->money($request->validated('amount')),
                tenureMonths: $request->validated('tenure_months'),
                productId: $product?->id,
                monthlyChange: $this->money($request->validated('monthly_change')),
                monthlyAmount: $this->money($request->validated('monthly_amount')),
                months: $request->validated('months'),
            ),
            $product,
        );

        /*
         * And remember that they explored it.
         *
         * After the projection, from the projection — so the record is
         * evidence of what they were actually shown rather than a second
         * calculation that could differ from it. A simulation stays a
         * simulation: nothing here writes an observation, moves a score or
         * touches their profile.
         */
        $this->decisions->simulated($request->user(), $projection, $product);

        return response()->json(['data' => $projection->toArray()]);
    }

    private function currentState(Request $request)
    {
        return $this->financial->snapshot(
            $this->customers->financialProfileFor($request->user())
        );
    }

    private function money(mixed $value): ?Money
    {
        return $value === null ? null : Money::of($value);
    }
}
