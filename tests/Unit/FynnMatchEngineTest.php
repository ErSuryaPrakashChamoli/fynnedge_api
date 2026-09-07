<?php

namespace Tests\Unit;

use App\Domain\Catalog\MockProductRepository;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Match\CriterionStatus;
use App\Domain\Match\FynnMatchEngine;
use App\Domain\Match\MatchCategory;
use App\Domain\Match\MatchRequest;
use App\Domain\Match\ProductMatch;
use App\Domain\Money;
use App\Services\FinanceService;
use PHPUnit\Framework\TestCase;

/**
 * FynnMatch, tested without HTTP, database or container.
 */
class FynnMatchEngineTest extends TestCase
{
    private FynnMatchEngine $engine;

    private MockProductRepository $catalogue;

    private FinancialEngine $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue = new MockProductRepository;
        $this->financial = new FinancialEngine;
        $this->engine = new FynnMatchEngine(
            $this->catalogue,
            new FinanceService,
            $this->financial,
        );
    }

    private function financials(
        string $income = '100000.00',
        string $emi = '20000.00',
        string $expenses = '40000.00',
    ) {
        return $this->financial->calculate(
            income: Money::of($income),
            expenses: Money::of($expenses),
            existingEmi: Money::of($emi),
            otherObligations: Money::zero(),
            savings: Money::of('180000.00'),
        );
    }

    private function request(
        string $amount = '1000000.00',
        int $tenure = 60,
        ?string $purpose = 'business',
    ): MatchRequest {
        return new MatchRequest(
            amount: Money::of($amount),
            tenureMonths: $tenure,
            purpose: $purpose,
        );
    }

    /** @param list<ProductMatch> $matches */
    private function byId(array $matches, string $id): ProductMatch
    {
        foreach ($matches as $m) {
            if ($m->product->id === $id) {
                return $m;
            }
        }
        $this->fail("No match for {$id}");
    }

    private function criterion(ProductMatch $match, string $key)
    {
        foreach ($match->criteria as $c) {
            if ($c->key === $key) {
                return $c;
            }
        }
        $this->fail("No criterion {$key}");
    }

    // --- Determinism -------------------------------------------------------

    public function test_the_same_inputs_produce_the_same_result(): void
    {
        $a = $this->engine->match($this->request(), $this->financials(), 34);
        $b = $this->engine->match($this->request(), $this->financials(), 34);

        $this->assertSame(
            array_map(fn (ProductMatch $m) => $m->toArray(), $a),
            array_map(fn (ProductMatch $m) => $m->toArray(), $b),
        );
    }

    public function test_ordering_is_deterministic_and_never_random(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $matches = $this->engine->match($this->request(), $this->financials(), 34);
            $ids[] = array_map(fn (ProductMatch $m) => $m->product->id, $matches);
        }

        $this->assertSame(array_fill(0, 5, $ids[0]), $ids);
    }

    public function test_viable_matches_sort_before_rejected_ones(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);

        $seenRejected = false;
        foreach ($matches as $m) {
            if (! $m->category->isViable()) {
                $seenRejected = true;

                continue;
            }
            $this->assertFalse(
                $seenRejected,
                'A viable match sorted after a rejected one',
            );
        }
    }

    // --- Purpose -----------------------------------------------------------

    public function test_a_business_request_matches_only_business_products(): void
    {
        $matches = $this->engine->match($this->request(purpose: 'business'), $this->financials(), 34);

        foreach ($matches as $m) {
            $purpose = $this->criterion($m, 'purpose');
            $this->assertSame(
                $m->product->category === 'business',
                $purpose->status === CriterionStatus::Passed,
            );
        }
    }

    public function test_a_purpose_mismatch_is_decisive(): void
    {
        $matches = $this->engine->match($this->request(purpose: 'business'), $this->financials(), 34);

        // A personal loan cannot be a match for a business purpose.
        $this->assertSame(
            MatchCategory::NotAMatch,
            $this->byId($matches, 'prod_pl_a')->category,
        );
    }

    public function test_a_missing_purpose_is_unavailable_not_a_pass(): void
    {
        $matches = $this->engine->match($this->request(purpose: null), $this->financials(), 34);

        $this->assertSame(
            CriterionStatus::Unavailable,
            $this->criterion($matches[0], 'purpose')->status,
        );
    }

    // --- Amount ------------------------------------------------------------

    public function test_an_amount_below_a_products_minimum_fails(): void
    {
        // Home loans start at ₹25L.
        $matches = $this->engine->match(
            $this->request(amount: '500000.00', purpose: 'home'),
            $this->financials(),
            34,
        );
        $match = $this->byId($matches, 'prod_hl_a');

        $this->assertSame(CriterionStatus::Failed, $this->criterion($match, 'amount')->status);
        $this->assertSame(MatchCategory::NotAMatch, $match->category);
        $this->assertStringContainsString('starts at', $this->criterion($match, 'amount')->detail);
    }

    public function test_an_amount_above_a_products_maximum_fails(): void
    {
        // Business Growth Loan stops at ₹50L.
        $matches = $this->engine->match(
            $this->request(amount: '60000000.00'),
            $this->financials(),
            34,
        );
        $match = $this->byId($matches, 'prod_bl_a');

        $this->assertSame(CriterionStatus::Failed, $this->criterion($match, 'amount')->status);
        $this->assertStringContainsString('stops at', $this->criterion($match, 'amount')->detail);
    }

    public function test_the_requested_amount_is_never_silently_changed(): void
    {
        $request = $this->request(amount: '500000.00', purpose: 'home');
        $matches = $this->engine->match($request, $this->financials(), 34);

        foreach ($matches as $m) {
            $this->assertSame(
                $request->amount->paise,
                $m->pricedAmount->paise,
                'FynnMatch rewrote the customer\'s requested amount',
            );
        }
    }

    // --- Tenure ------------------------------------------------------------

    public function test_an_unsupported_tenure_is_a_caution_and_the_pricing_says_so(): void
    {
        // SME Term Loan runs 24-84 months; ask for 12.
        $matches = $this->engine->match($this->request(tenure: 12), $this->financials(), 34);
        $match = $this->byId($matches, 'prod_bl_b');

        $this->assertSame(CriterionStatus::Caution, $this->criterion($match, 'tenure')->status);
        $this->assertSame(MatchCategory::Review, $match->category);
        $this->assertTrue($match->tenureWasAdjusted);
        $this->assertSame(24, $match->pricedTenureMonths);
    }

    // --- Minimum income ----------------------------------------------------

    public function test_income_below_a_documented_minimum_fails(): void
    {
        // Meridian documents ₹40,000; this customer earns ₹25,000.
        $matches = $this->engine->match(
            $this->request(purpose: 'personal', amount: '600000.00'),
            $this->financials(income: '25000.00', emi: '0.00', expenses: '10000.00'),
            34,
        );
        $match = $this->byId($matches, 'prod_pl_a');

        $this->assertSame(CriterionStatus::Failed, $this->criterion($match, 'minimum_income')->status);
        $this->assertSame(MatchCategory::NotAMatch, $match->category);
    }

    public function test_income_above_a_documented_minimum_passes(): void
    {
        $matches = $this->engine->match(
            $this->request(purpose: 'personal', amount: '600000.00'),
            $this->financials(),
            34,
        );

        $this->assertSame(
            CriterionStatus::Passed,
            $this->criterion($this->byId($matches, 'prod_pl_a'), 'minimum_income')->status,
        );
    }

    public function test_a_product_without_a_documented_minimum_reports_unavailable(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);
        $criterion = $this->criterion($this->byId($matches, 'prod_bl_a'), 'minimum_income');

        // Never "passed": we did not check anything.
        $this->assertSame(CriterionStatus::Unavailable, $criterion->status);
        $this->assertStringContainsString('does not publish', $criterion->detail);
    }

    public function test_an_unknown_income_reports_unavailable_not_failed(): void
    {
        $matches = $this->engine->match(
            $this->request(purpose: 'personal', amount: '600000.00'),
            $this->financials(income: '0.00', emi: '0.00', expenses: '0.00'),
            34,
        );

        $this->assertSame(
            CriterionStatus::Unavailable,
            $this->criterion($this->byId($matches, 'prod_pl_a'), 'minimum_income')->status,
        );
    }

    // --- Age ---------------------------------------------------------------

    public function test_age_beyond_the_documented_maturity_limit_fails(): void
    {
        // Meridian documents 23-58 at maturity; 56 + 5 years = 61.
        $matches = $this->engine->match(
            $this->request(purpose: 'personal', amount: '600000.00'),
            $this->financials(),
            56,
        );
        $match = $this->byId($matches, 'prod_pl_a');

        $this->assertSame(CriterionStatus::Failed, $this->criterion($match, 'age')->status);
        $this->assertStringContainsString('61', $this->criterion($match, 'age')->detail);
    }

    public function test_an_unknown_age_reports_unavailable(): void
    {
        $matches = $this->engine->match(
            $this->request(purpose: 'personal', amount: '600000.00'),
            $this->financials(),
            null,
        );

        $this->assertSame(
            CriterionStatus::Unavailable,
            $this->criterion($this->byId($matches, 'prod_pl_a'), 'age')->status,
        );
    }

    // --- Affordability -----------------------------------------------------

    public function test_a_projected_emi_past_the_ceiling_is_a_warning_not_a_rejection(): void
    {
        // Existing EMIs already 35% of income; a new loan pushes well past 45%.
        $matches = $this->engine->match(
            $this->request(),
            $this->financials(income: '100000.00', emi: '35000.00'),
            34,
        );
        $match = $this->byId($matches, 'prod_bl_a');

        $this->assertSame(
            CriterionStatus::Caution,
            $this->criterion($match, 'affordability')->status,
        );
        // A warning, because no product documents an EMI-ratio rule.
        $this->assertSame(MatchCategory::Review, $match->category);
        $this->assertTrue($match->category->isViable());
    }

    public function test_the_projected_ratio_includes_existing_emis(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);
        $match = $this->byId($matches, 'prod_bl_a');

        // (20,000 existing + 22,881.26 new) / 100,000
        $this->assertEqualsWithDelta(42.88, $match->projectedEmiRatio(), 0.01);
    }

    public function test_the_ceiling_is_the_products_existing_definition(): void
    {
        $this->assertSame(45.0, FynnMatchEngine::AFFORDABILITY_CEILING_PERCENT);
    }

    public function test_affordability_is_unavailable_without_an_income(): void
    {
        $matches = $this->engine->match(
            $this->request(),
            $this->financials(income: '0.00', emi: '0.00', expenses: '0.00'),
            34,
        );
        $match = $this->byId($matches, 'prod_bl_a');

        $this->assertSame(
            CriterionStatus::Unavailable,
            $this->criterion($match, 'affordability')->status,
        );
        $this->assertNull($match->projectedEmiRatio());
    }

    // --- Categories --------------------------------------------------------

    public function test_a_fully_checked_passing_product_is_a_strong_match(): void
    {
        // Meridian documents both income and age, so nothing is unassessed.
        $matches = $this->engine->match(
            $this->request(purpose: 'personal', amount: '600000.00', tenure: 60),
            $this->financials(income: '100000.00', emi: '5000.00'),
            34,
        );
        $match = $this->byId($matches, 'prod_pl_a');

        $this->assertSame(MatchCategory::Strong, $match->category);
        $this->assertSame([], $match->withStatus(CriterionStatus::Unavailable));
    }

    public function test_an_unchecked_criterion_downgrades_to_good_not_strong(): void
    {
        // Arcus publishes neither income nor age requirements.
        $matches = $this->engine->match($this->request(), $this->financials(income: '100000.00', emi: '5000.00'), 34);
        $match = $this->byId($matches, 'prod_bl_a');

        $this->assertSame(MatchCategory::Good, $match->category);
        $this->assertNotEmpty($match->withStatus(CriterionStatus::Unavailable));
    }

    public function test_no_matching_products_yields_an_empty_viable_set(): void
    {
        // An amount no product in the catalogue serves.
        $matches = $this->engine->match(
            $this->request(amount: '900000000.00'),
            $this->financials(),
            34,
        );

        $viable = array_filter($matches, fn (ProductMatch $m) => $m->category->isViable());
        $this->assertSame([], $viable);
        $this->assertCount(6, $matches, 'every product is still explained');
    }

    // --- Pricing -----------------------------------------------------------

    public function test_pricing_uses_the_existing_finance_engine(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);
        $match = $this->byId($matches, 'prod_bl_a');

        // ₹10L at 13.25% over 60 months, the figure verified in Module 0.
        $this->assertSame('22881.26', $match->emi->toDecimalString());
        $this->assertSame('17500.00', $match->processingFee->toDecimalString());
    }

    public function test_a_capped_processing_fee_is_respected(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);

        // SME Term Loan: 1% capped at ₹25,000, so ₹10L means ₹10,000.
        $this->assertSame(
            '10000.00',
            $this->byId($matches, 'prod_bl_b')->processingFee->toDecimalString(),
        );
    }

    // --- Boundaries --------------------------------------------------------

    public function test_it_never_claims_approval(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);

        // FynnMatch's own words only. Product copy legitimately describes a
        // lender's process ("approval takes longer"); what must never appear
        // is FynnEdge asserting an outcome in a criterion or a category.
        $ourWords = [];
        foreach ($matches as $match) {
            $ourWords[] = $match->category->label();
            foreach ($match->criteria as $criterion) {
                $ourWords[] = $criterion->label.' '.$criterion->detail;
            }
        }
        $ourWords[] = FynnMatchEngine::DISCLAIMER;
        $text = implode(' ', $ourWords);

        foreach (['approved', 'guaranteed', 'pre-approved', 'you qualify', 'eligible for'] as $phrase) {
            $this->assertStringNotContainsStringIgnoringCase(
                $phrase,
                $text,
                "FynnMatch claimed \"{$phrase}\"",
            );
        }
    }

    public function test_criterion_wording_states_the_actual_outcome(): void
    {
        $matches = $this->engine->match($this->request(), $this->financials(), 34);
        $business = $this->byId($matches, 'prod_bl_a');
        $personal = $this->byId($matches, 'prod_pl_a');

        $this->assertSame(
            'This is a business loan, which is what you asked for.',
            $this->criterion($business, 'purpose')->detail,
        );
        $this->assertSame(
            'This is a personal loan, not what you asked for.',
            $this->criterion($personal, 'purpose')->detail,
        );

        // The summary arrays repeat the criteria verbatim.
        $this->assertContains(
            $this->criterion($business, 'purpose')->detail,
            $business->toArray()['match']['fits'],
        );
        $this->assertContains(
            $this->criterion($personal, 'purpose')->detail,
            $personal->toArray()['match']['blockers'],
        );
    }

    public function test_the_disclaimer_states_the_lender_decides(): void
    {
        $this->assertStringContainsString('not an approval', FynnMatchEngine::DISCLAIMER);
        $this->assertStringContainsString('provider', FynnMatchEngine::DISCLAIMER);
    }

    public function test_every_product_is_clearly_sample_data(): void
    {
        $this->assertTrue($this->catalogue->isSampleData());
        foreach ($this->catalogue->all() as $product) {
            $this->assertTrue($product->toArray()['is_sample_data']);
        }
    }
}
