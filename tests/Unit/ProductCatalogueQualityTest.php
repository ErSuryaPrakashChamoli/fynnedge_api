<?php

namespace Tests\Unit;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\MalformedProductException;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What FynnEdge is willing to call a product.
 *
 * The catalogue is invented sample data today and will one day be a real
 * feed. Either way a record whose own numbers contradict each other must
 * stop here: a range that runs backwards would otherwise reach FynnMatch,
 * be evaluated, and become a sentence on a decision screen that nobody
 * could stand behind.
 */
class ProductCatalogueQualityTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): LoanProduct
    {
        $a = array_merge([
            'id' => 'prod_test',
            'lender' => 'Test Lender',
            'name' => 'Test Loan',
            'lenderTag' => '',
            'category' => 'personal',
            'minAmount' => Money::of(100000),
            'maxAmount' => Money::of(1000000),
            'interestRate' => 12.0,
            'minTenureMonths' => 12,
            'maxTenureMonths' => 60,
            'processingFeePercent' => 1.0,
            'processingFeeCap' => null,
            'fynnTrust' => 80,
            'prepaymentCharge' => '',
            'suitedReasons' => [],
            'considerations' => [],
            'conditions' => [],
            'trustBreakdown' => [],
            'minMonthlyIncome' => null,
            'maxAgeAtMaturity' => null,
            'minAge' => null,
        ], $overrides);

        return new LoanProduct(...$a);
    }

    public function test_a_coherent_product_is_accepted(): void
    {
        $this->assertSame('prod_test', $this->product()->id);
    }

    #[DataProvider('malformedRecords')]
    public function test_a_malformed_product_cannot_be_built(
        array $overrides,
        string $expectedProblem,
    ): void {
        try {
            $this->product($overrides);
            $this->fail("A product with {$expectedProblem} was accepted");
        } catch (MalformedProductException $e) {
            $this->assertStringContainsString($expectedProblem, $e->getMessage());
            // The message names the record so whoever is fixing a feed can
            // find it; an empty id is reported as such rather than blank.
            $expectedName = ($overrides['id'] ?? 'prod_test') === ''
                ? '(no id)'
                : 'prod_test';
            $this->assertStringContainsString($expectedName, $e->getMessage());
        }
    }

    public static function malformedRecords(): array
    {
        return [
            'amounts inverted' => [
                ['minAmount' => Money::of(1000000), 'maxAmount' => Money::of(100000)],
                'min_amount is above max_amount',
            ],
            'negative amount' => [
                ['minAmount' => Money::of(-1)],
                'amounts cannot be negative',
            ],
            'tenures inverted' => [
                ['minTenureMonths' => 84, 'maxTenureMonths' => 12],
                'min_tenure_months is above max_tenure_months',
            ],
            'zero tenure' => [
                ['minTenureMonths' => 0],
                'min_tenure_months must be at least one month',
            ],
            'negative rate' => [
                ['interestRate' => -1.5],
                'interest_rate cannot be negative',
            ],
            'negative fee' => [
                ['processingFeePercent' => -1.0],
                'processing_fee_percent cannot be negative',
            ],
            'negative fee cap' => [
                ['processingFeeCap' => Money::of(-500)],
                'processing_fee_cap cannot be negative',
            ],
            'trust above scale' => [
                ['fynnTrust' => 150],
                'fynn_trust is outside 0–100',
            ],
            'trust below scale' => [
                ['fynnTrust' => -5],
                'fynn_trust is outside 0–100',
            ],
            'trust factor off scale' => [
                ['trustBreakdown' => [
                    'cost' => ['label' => 'Cost', 'value' => 240, 'explanation' => 'x'],
                ]],
                "trust factor 'cost' is outside 0–100",
            ],
            'negative minimum income' => [
                ['minMonthlyIncome' => Money::of(-1)],
                'min_monthly_income cannot be negative',
            ],
            'impossible minimum age' => [
                ['minAge' => 4],
                'min_age is outside 18–100',
            ],
            'impossible maturity age' => [
                ['maxAgeAtMaturity' => 140],
                'max_age_at_maturity is outside 18–100',
            ],
            'age range inverted' => [
                ['minAge' => 60, 'maxAgeAtMaturity' => 30],
                'min_age is above max_age_at_maturity',
            ],
            'no lender' => [
                ['lender' => '  '],
                'lender and name are both required',
            ],
            'no id' => [['id' => ''], 'id is empty'],
        ];
    }

    public function test_an_interest_free_product_is_allowed(): void
    {
        // Zero is not malformed. A 0% product is a real thing and
        // FinanceService already divides the principal evenly for it.
        $this->assertSame(0.0, $this->product(['interestRate' => 0])->interestRate);
    }

    public function test_every_shipped_product_is_coherent(): void
    {
        // The constructor throws, so simply loading the catalogue proves it.
        $products = app(ProductRepository::class)->all();

        $this->assertNotEmpty($products);

        foreach ($products as $product) {
            $this->assertNotEmpty($product->id);
            $this->assertGreaterThanOrEqual(0, $product->fynnTrust);
            $this->assertLessThanOrEqual(100, $product->fynnTrust);
            $this->assertLessThanOrEqual(0, $product->minAmount->compareTo($product->maxAmount));
            $this->assertLessThanOrEqual($product->maxTenureMonths, $product->minTenureMonths);
        }
    }

    public function test_the_catalogue_says_it_is_sample_data(): void
    {
        $repository = app(ProductRepository::class);

        $this->assertTrue(
            $repository->isSampleData(),
            'The catalogue is invented; saying otherwise would make six made-up '
            .'products look like market pricing.',
        );

        foreach ($repository->all() as $product) {
            $this->assertTrue($product->toArray()['is_sample_data']);
        }
    }

    public function test_no_product_claims_something_a_provider_would_decide(): void
    {
        $text = '';
        foreach (app(ProductRepository::class)->all() as $product) {
            $text .= json_encode([
                $product->lenderTag,
                $product->suitedReasons,
                $product->considerations,
                $product->conditions,
                $product->trustBreakdown,
            ]);
        }

        // No application reaches a provider, so nothing in a product record
        // may describe how one behaves, how fast it is, or how likely it is
        // to say yes.
        foreach ([
            'approval chances', 'likely to approve', 'guaranteed',
            'working days', 'instant', 'pre-approved', 'fast disbursal',
            'quick approval', 'cheapest money available',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase(
                $claim,
                $text,
                "A sample product claims: {$claim}",
            );
        }
    }
}
