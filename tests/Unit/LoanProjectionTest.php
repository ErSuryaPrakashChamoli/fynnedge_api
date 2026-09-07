<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Match\FynnMatchEngine;
use App\Domain\Money;
use Tests\TestCase;

/**
 * The projection is the one place FynnEdge works out what a loan would do to
 * a customer's month. Every before/after pair on Loan Detail, FynnMirror and
 * every match card reads it, so its arithmetic is pinned here.
 */
class LoanProjectionTest extends TestCase
{
    private FinancialEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new FinancialEngine;
    }

    private function snapshot(
        string $income = '100000.00',
        string $expenses = '40000.00',
        string $emi = '20000.00',
    ) {
        return $this->engine->calculate(
            income: Money::of($income),
            expenses: Money::of($expenses),
            existingEmi: Money::of($emi),
            otherObligations: Money::zero(),
            savings: Money::of('180000.00'),
        );
    }

    public function test_it_adds_the_emi_to_outgo_and_takes_it_from_surplus(): void
    {
        $p = $this->engine->project($this->snapshot(), Money::of('15000.00'));

        $this->assertSame(60000, $p->currentOutgo->toJsonNumber());
        $this->assertSame(75000, $p->projectedOutgo->toJsonNumber());
        $this->assertSame(40000, $p->currentSurplus->toJsonNumber());
        $this->assertSame(25000, $p->projectedSurplus->toJsonNumber());
    }

    public function test_the_projected_ratio_counts_existing_emis_too(): void
    {
        $p = $this->engine->project($this->snapshot(), Money::of('15000.00'));

        $this->assertSame(20, $p->currentEmiRatio->toJsonNumber());
        // (20000 + 15000) / 100000
        $this->assertSame(35, $p->projectedEmiRatio->toJsonNumber());
    }

    public function test_the_ceiling_and_headroom_come_from_the_income(): void
    {
        $p = $this->engine->project($this->snapshot(), Money::of('15000.00'));

        $this->assertSame(45000, $p->emiCeiling->toJsonNumber());
        $this->assertSame(25000, $p->headroomBefore->toJsonNumber());
        $this->assertSame(10000, $p->headroomAfter->toJsonNumber());
        $this->assertTrue($p->withinCeiling);
    }

    public function test_headroom_never_goes_negative(): void
    {
        $p = $this->engine->project(
            $this->snapshot(emi: '50000.00'),
            Money::of('10000.00'),
        );

        $this->assertSame(0, $p->headroomBefore->toJsonNumber());
        $this->assertSame(0, $p->headroomAfter->toJsonNumber());
        $this->assertFalse($p->withinCeiling);
    }

    public function test_exactly_on_the_ceiling_is_still_inside_it(): void
    {
        // 20,000 existing + 25,000 = 45,000 = exactly 45% of 100,000.
        $p = $this->engine->project($this->snapshot(), Money::of('25000.00'));

        $this->assertSame(45, $p->projectedEmiRatio->toJsonNumber());
        $this->assertTrue($p->withinCeiling);
        $this->assertSame(0, $p->headroomAfter->toJsonNumber());
    }

    public function test_past_the_ceiling_is_outside_it_at_the_ratio_precision(): void
    {
        // Ratios are held to two decimals everywhere in the product, so the
        // boundary is judged there too: 45.004% still reads as 45%, and
        // 45.01% does not. A paise-level cliff would be false precision on a
        // reference FynnEdge sets itself.
        $rounds = $this->engine->project($this->snapshot(), Money::of('25000.01'));
        $this->assertSame(45, $rounds->projectedEmiRatio->toJsonNumber());
        $this->assertTrue($rounds->withinCeiling);

        $over = $this->engine->project($this->snapshot(), Money::of('25010.00'));
        $this->assertSame(45.01, $over->projectedEmiRatio->toJsonNumber());
        $this->assertFalse($over->withinCeiling);
    }

    public function test_a_surplus_it_would_wipe_out_is_flagged(): void
    {
        $comfortable = $this->engine->project($this->snapshot(), Money::of('15000.00'));
        $this->assertFalse($comfortable->exhaustsSurplus());

        $tight = $this->engine->project($this->snapshot(), Money::of('40000.00'));
        $this->assertTrue($tight->exhaustsSurplus());
        $this->assertSame(0, $tight->projectedSurplus->toJsonNumber());
    }

    public function test_a_negative_projected_surplus_is_shown_not_hidden(): void
    {
        $p = $this->engine->project($this->snapshot(), Money::of('55000.00'));

        // Spending past your income is a fact worth showing.
        $this->assertSame(-15000, $p->projectedSurplus->toJsonNumber());
        $this->assertTrue($p->exhaustsSurplus());
    }

    public function test_without_an_income_nothing_is_claimed(): void
    {
        $p = $this->engine->project(
            $this->snapshot(income: '0.00', expenses: '20000.00', emi: '0.00'),
            Money::of('10000.00'),
        );

        $this->assertFalse($p->isMeasurable);
        // Not "within the ceiling" — there is no ceiling to be within.
        $this->assertFalse($p->withinCeiling);
        $this->assertSame(0, $p->emiCeiling->toJsonNumber());
        $this->assertFalse($p->exhaustsSurplus());
    }

    public function test_a_zero_emi_projection_is_the_current_position(): void
    {
        // This is how the API reports standing affordability: same call, no
        // new loan, so there is only ever one definition of the headroom.
        $p = $this->engine->project($this->snapshot(), Money::zero());

        $this->assertSame(
            $p->currentOutgo->toJsonNumber(),
            $p->projectedOutgo->toJsonNumber(),
        );
        $this->assertSame(
            $p->currentEmiRatio->toJsonNumber(),
            $p->projectedEmiRatio->toJsonNumber(),
        );
        $this->assertSame(
            $p->headroomBefore->toJsonNumber(),
            $p->headroomAfter->toJsonNumber(),
        );
    }

    public function test_it_always_declares_itself_an_estimate(): void
    {
        $array = $this->engine->project($this->snapshot(), Money::of('15000.00'))->toArray();

        $this->assertTrue($array['is_estimate']);
        $this->assertSame(45.0, $array['ceiling_percent']);
    }

    public function test_the_ceiling_is_defined_once_for_the_whole_product(): void
    {
        $this->assertSame(
            FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
            FynnMatchEngine::AFFORDABILITY_CEILING_PERCENT,
        );
    }
}
