<?php

namespace App\Http\Controllers\Api;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The product catalogue. Read-only: nothing a customer sends can change it.
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductRepository $products) {}

    public function index(Request $request): JsonResponse
    {
        $category = $request->query('category');
        $amount = $request->query('amount');

        $products = $this->products->all(
            is_string($category) && $category !== '' ? $category : null,
        );

        if ($amount !== null && is_numeric($amount)) {
            $wanted = Money::of((string) $amount);
            $products = array_values(array_filter(
                $products,
                fn (LoanProduct $p) => $p->supportsAmount($wanted),
            ));
        }

        return response()->json([
            'data' => array_map(
                fn (LoanProduct $p) => $p->toArray(),
                $products,
            ),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $product = $this->products->find($id);

        if ($product === null) {
            return response()->json(
                ['message' => 'That offer is no longer available.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return response()->json(['data' => $product->toArray()]);
    }

    /**
     * The app compares locally so the maths stays consistent offline; this
     * endpoint exists for server-side consumers and audit trails.
     */
    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:2', 'max:3'],
            'product_ids.*' => ['string'],
        ]);

        $selected = [];
        foreach ($validated['product_ids'] as $id) {
            $product = $this->products->find($id);
            if ($product !== null) {
                $selected[] = $product->toArray();
            }
        }

        return response()->json(['data' => $selected]);
    }
}
