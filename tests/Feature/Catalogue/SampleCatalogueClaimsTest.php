<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Catalog\UnsupportedProductClaims;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sample catalogue, held to the standard a published one is held to.
 *
 * CatalogueService runs every product's copy through
 * UnsupportedProductClaims before publication — lender tag, suited reasons,
 * considerations, conditions and trust breakdown. The sample repository is
 * a different source that never goes through publication, so none of its
 * copy had ever been checked by anything.
 *
 * That is where "Lowest rate in this set" was found: true of the two
 * business loans it sits among, and false of the catalogue it is served
 * from, which also holds a home loan at 8.6% and a loan against property at
 * 10.25%. Nothing renders lender_tag today, so no customer had read it —
 * but the API serves the field, and a latent false claim about pricing is
 * one screen away from being a real one.
 *
 * The denylist catches phrases. This also checks the arithmetic: a product
 * that says it is the lowest has to actually be the lowest of whatever it
 * claims to be lowest among.
 */
class SampleCatalogueClaimsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<LoanProduct> */
    private function products(): array
    {
        return app(ProductRepository::class)->all();
    }

    public function test_the_sample_catalogue_is_not_a_source_of_unchecked_copy(): void
    {
        $products = $this->products();

        $this->assertNotEmpty($products, 'the sample catalogue was empty');

        foreach ($products as $product) {
            $copy = strtolower((string) json_encode([
                $product->lenderTag,
                $product->suitedReasons,
                $product->considerations,
                $product->conditions,
            ]));

            $this->assertNull(
                UnsupportedProductClaims::firstIn($copy),
                "[{$product->id}] claims something FynnEdge cannot support: "
                .UnsupportedProductClaims::firstIn($copy),
            );
        }
    }

    public function test_a_product_that_says_it_is_cheapest_actually_is(): void
    {
        $products = $this->products();
        $checked = 0;

        foreach ($products as $product) {
            $claim = strtolower($product->lenderTag);

            if (! str_contains($claim, 'lowest')) {
                continue;
            }

            $checked++;

            /*
             * A superlative has to name what it is superlative among, and
             * the only scope the sample catalogue has is the category. An
             * unscoped "lowest rate" is measured against everything the
             * repository serves, which is the reading a customer browsing
             * across categories would take.
             */
            $scope = str_contains($claim, $product->category)
                ? array_filter(
                    $products,
                    fn (LoanProduct $p) => $p->category === $product->category,
                )
                : $products;

            $lowest = min(array_map(
                fn (LoanProduct $p) => $p->interestRate,
                $scope,
            ));

            $this->assertSame(
                $lowest,
                $product->interestRate,
                "[{$product->id}] says \"{$product->lenderTag}\" at "
                ."{$product->interestRate}%, but {$lowest}% is lower in the "
                .'set that claim is measured against',
            );
        }

        // The check has to be reaching something, or it is a guard over an
        // empty set.
        $this->assertGreaterThan(0, $checked, 'no product makes a lowest claim');
    }

    public function test_the_sample_catalogue_says_it_is_a_sample(): void
    {
        // Module 19's rule: sample pricing must never be mistaken for a
        // live market. Whatever else the copy says, it may not present
        // itself as somebody's real offer.
        foreach ($this->products() as $product) {
            $copy = strtolower((string) json_encode([
                $product->lenderTag,
                $product->suitedReasons,
            ]));

            foreach (['approved', 'pre-approved', 'you qualify', 'offer for you'] as $claim) {
                $this->assertStringNotContainsString($claim, $copy);
            }
        }
    }
}
