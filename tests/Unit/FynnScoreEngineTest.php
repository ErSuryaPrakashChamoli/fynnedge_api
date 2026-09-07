<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Money;
use App\Domain\Score\FynnScoreEngine;
use App\Domain\Score\ScoreBand;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FynnScore v1, tested without HTTP, database or container.
 */
class FynnScoreEngineTest extends TestCase
{
    private FynnScoreEngine $scoring;

    private FinancialEngine $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scoring = new FynnScoreEngine;
        $this->financial = new FinancialEngine;
    }

    /** @param list<array<string, mixed>> $goals */
    private function score(
        string $income,
        string $expenses,
        string $emi,
        string $savings = '0.00',
        string $obligations = '0.00',
        array $goals = [],
    ) {
        $snapshot = $this->financial->calculate(
            income: Money::of($income),
            expenses: Money::of($expenses),
            existingEmi: Money::of($emi),
            otherObligations: Money::of($obligations),
            savings: Money::of($savings),
            goals: $goals,
            asOf: Carbon::parse('2026-09-05T10:30:00+00:00'),
        );

        return $this->scoring->score($snapshot);
    }

    private function dimension($score, string $key): array
    {
        foreach ($score->dimensions as $d) {
            if ($d->key === $key) {
                return $d->toArray();
            }
        }
        $this->fail("No dimension {$key}");
    }

    // --- 1. Determinism ----------------------------------------------------

    public function test_the_same_inputs_always_produce_the_same_score(): void
    {
        $a = $this->score('100000.00', '40000.00', '20000.00', '180000.00');
        $b = $this->score('100000.00', '40000.00', '20000.00', '180000.00');

        $this->assertSame($a->toArray(), $b->toArray());
    }

    public function test_the_score_does_not_depend_on_the_clock(): void
    {
        // Same inputs, two different "as of" dates.
        $early = $this->scoring->score($this->financial->calculate(
            income: Money::of('100000.00'),
            expenses: Money::of('40000.00'),
            existingEmi: Money::of('20000.00'),
            otherObligations: Money::zero(),
            savings: Money::of('180000.00'),
            asOf: Carbon::parse('2026-01-01T00:00:00+00:00'),
        ));
        $late = $this->scoring->score($this->financial->calculate(
            income: Money::of('100000.00'),
            expenses: Money::of('40000.00'),
            existingEmi: Money::of('20000.00'),
            otherObligations: Money::zero(),
            savings: Money::of('180000.00'),
            asOf: Carbon::parse('2031-12-31T00:00:00+00:00'),
        ));

        $this->assertSame($early->score, $late->score);
    }

    // --- 2-4. One metric moves one dimension -------------------------------

    public function test_changing_the_emi_ratio_moves_only_the_emi_dimension(): void
    {
        $before = $this->score('100000.00', '40000.00', '10000.00', '360000.00');
        $after = $this->score('100000.00', '40000.00', '40000.00', '360000.00');

        $this->assertNotSame(
            $this->dimension($before, 'emi_burden')['score'],
            $this->dimension($after, 'emi_burden')['score'],
        );
        $this->assertSame(
            $this->dimension($before, 'spending_control')['score'],
            $this->dimension($after, 'spending_control')['score'],
        );
    }

    public function test_changing_the_expense_ratio_moves_only_the_spending_dimension(): void
    {
        $before = $this->score('100000.00', '30000.00', '20000.00', '300000.00');
        $after = $this->score('100000.00', '70000.00', '20000.00', '300000.00');

        $this->assertNotSame(
            $this->dimension($before, 'spending_control')['score'],
            $this->dimension($after, 'spending_control')['score'],
        );
        $this->assertSame(
            $this->dimension($before, 'emi_burden')['score'],
            $this->dimension($after, 'emi_burden')['score'],
        );
    }

    public function test_changing_savings_moves_only_the_emergency_dimension(): void
    {
        $before = $this->score('100000.00', '40000.00', '20000.00', '60000.00');
        $after = $this->score('100000.00', '40000.00', '20000.00', '360000.00');

        $this->assertNotSame(
            $this->dimension($before, 'emergency_buffer')['score'],
            $this->dimension($after, 'emergency_buffer')['score'],
        );
        $this->assertSame(
            $this->dimension($before, 'emi_burden')['score'],
            $this->dimension($after, 'emi_burden')['score'],
        );
        $this->assertSame(
            $this->dimension($before, 'spending_control')['score'],
            $this->dimension($after, 'spending_control')['score'],
        );
    }

    // --- 5. Goals never move the score -------------------------------------

    public function test_goal_progress_does_not_change_the_score(): void
    {
        $asOf = Carbon::parse('2026-09-05T10:30:00+00:00');

        $barelyStarted = [[
            'id' => '1', 'title' => 'g', 'target' => Money::of('500000.00'),
            'saved' => Money::of('1000.00'),
            'target_date' => $asOf->copy()->addDays(30),
        ]];
        $nearlyDone = [[
            'id' => '1', 'title' => 'g', 'target' => Money::of('500000.00'),
            'saved' => Money::of('495000.00'),
            'target_date' => $asOf->copy()->addDays(3000),
        ]];

        $weak = $this->score('120000.00', '48000.00', '18000.00', '400000.00', '0.00', $barelyStarted);
        $strong = $this->score('120000.00', '48000.00', '18000.00', '400000.00', '0.00', $nearlyDone);

        $this->assertSame($weak->score, $strong->score);
        $this->assertSame($weak->toArray(), $strong->toArray());
    }

    public function test_no_scoring_dimension_reads_a_goal_metric(): void
    {
        $score = $this->score('100000.00', '40000.00', '20000.00', '180000.00');

        foreach ($score->dimensions as $dimension) {
            $this->assertContains(
                $dimension->metric,
                ['emi_ratio', 'expense_ratio', 'emergency_months'],
                'A scoring dimension reads a metric outside the v1 model',
            );
        }
    }

    // --- 6-7. Anchors ------------------------------------------------------

    public function test_an_emi_ratio_of_45_percent_reaches_the_zero_anchor(): void
    {
        // 45,000 of 100,000 income.
        $score = $this->score('100000.00', '30000.00', '45000.00', '450000.00');

        $this->assertSame(45, $this->dimension($score, 'emi_burden')['metric_value']);
        $this->assertSame(0, $this->dimension($score, 'emi_burden')['score']);
    }

    public function test_an_emi_ratio_of_20_percent_reaches_full_marks(): void
    {
        $score = $this->score('100000.00', '30000.00', '20000.00', '450000.00');

        $this->assertSame(100, $this->dimension($score, 'emi_burden')['score']);
    }

    public function test_six_months_of_cover_reaches_the_emergency_anchor(): void
    {
        // Outgo 50,000; savings 300,000 = exactly 6 months.
        $score = $this->score('100000.00', '40000.00', '10000.00', '300000.00');

        $this->assertSame(6, $this->dimension($score, 'emergency_buffer')['metric_value']);
        $this->assertSame(100, $this->dimension($score, 'emergency_buffer')['score']);
    }

    public function test_the_expense_anchors(): void
    {
        $full = $this->score('100000.00', '40000.00', '0.00', '600000.00');
        $zero = $this->score('100000.00', '80000.00', '0.00', '600000.00');

        $this->assertSame(100, $this->dimension($full, 'spending_control')['score']);
        $this->assertSame(0, $this->dimension($zero, 'spending_control')['score']);
    }

    public function test_the_curve_is_linear_at_the_midpoint(): void
    {
        // 32.5% is halfway between the 20% and 45% EMI anchors.
        $score = $this->score('100000.00', '30000.00', '32500.00', '450000.00');

        $this->assertSame(50, $this->dimension($score, 'emi_burden')['score']);
    }

    // --- 8-9. Range and bands ----------------------------------------------

    #[DataProvider('bandCases')]
    public function test_bands_follow_the_defined_breakpoints(int $score, ScoreBand $expected): void
    {
        $this->assertSame($expected, ScoreBand::forScore($score));
    }

    /** @return list<array{int, ScoreBand}> */
    public static function bandCases(): array
    {
        return [
            [100, ScoreBand::Strong],
            [75, ScoreBand::Strong],
            [74, ScoreBand::Good],
            [55, ScoreBand::Good],
            [54, ScoreBand::NeedsAttention],
            [40, ScoreBand::NeedsAttention],
            [39, ScoreBand::AtRisk],
            [0, ScoreBand::AtRisk],
        ];
    }

    public function test_the_score_is_always_within_zero_and_one_hundred(): void
    {
        $extremes = [
            ['1000000.00', '0.00', '0.00', '99999999.00'],
            ['1000.00', '999999.00', '999999.00', '0.00'],
            ['100000.00', '40000.00', '20000.00', '0.00'],
            ['1.00', '0.00', '0.00', '0.00'],
        ];

        foreach ($extremes as [$income, $expenses, $emi, $savings]) {
            $score = $this->score($income, $expenses, $emi, $savings);
            if (! $score->hasScore) {
                continue;
            }
            $this->assertGreaterThanOrEqual(0, $score->score);
            $this->assertLessThanOrEqual(100, $score->score);
        }
    }

    public function test_the_best_and_worst_cases_reach_the_ends_of_the_scale(): void
    {
        $best = $this->score('200000.00', '60000.00', '20000.00', '1000000.00');
        $worst = $this->score('50000.00', '45000.00', '30000.00', '0.00');

        $this->assertSame(100, $best->score);
        $this->assertSame(ScoreBand::Strong, $best->band);
        $this->assertSame(0, $worst->score);
        $this->assertSame(ScoreBand::AtRisk, $worst->band);
    }

    // --- 10. Insufficient data ---------------------------------------------

    public function test_zero_income_yields_insufficient_data_not_a_score(): void
    {
        $score = $this->score('0.00', '20000.00', '0.00', '50000.00');

        $this->assertFalse($score->hasScore);
        $this->assertNull($score->score);
        $this->assertNull($score->band);
        $this->assertSame([], $score->dimensions);
        $this->assertSame(['monthly_income'], $score->missingRequirements);
        $this->assertStringContainsString('monthly income', $score->insufficientDataReason);
    }

    public function test_an_empty_profile_yields_insufficient_data(): void
    {
        $score = $this->score('0.00', '0.00', '0.00', '0.00');

        $this->assertFalse($score->hasScore);
        $this->assertSame('insufficient_data', $score->toArray()['status']);
    }

    public function test_income_is_the_only_v1_requirement(): void
    {
        // Everything else zero, but income present: a score is produced.
        $score = $this->score('50000.00', '0.00', '0.00', '0.00');

        $this->assertTrue($score->hasScore);
        $this->assertNotNull($score->score);
    }

    // --- 11. Explanations trace to metrics ---------------------------------

    public function test_each_dimension_names_the_metric_it_reads(): void
    {
        $score = $this->score('100000.00', '40000.00', '20000.00', '180000.00');
        $array = $score->toArray();

        $expected = [
            'emi_burden' => 'emi_ratio',
            'spending_control' => 'expense_ratio',
            'emergency_buffer' => 'emergency_months',
        ];

        foreach ($array['dimensions'] as $d) {
            $this->assertSame($expected[$d['key']], $d['metric']);
            $this->assertNotEmpty($d['explanation']);
            $this->assertSame($array['metrics'][$d['metric']], $d['metric_value']);
        }
    }

    public function test_explanations_quote_the_actual_metric_value(): void
    {
        $score = $this->score('100000.00', '40000.00', '20000.00', '180000.00');

        $this->assertStringContainsString('20%', $this->dimension($score, 'emi_burden')['explanation']);
        $this->assertStringContainsString('40%', $this->dimension($score, 'spending_control')['explanation']);
        $this->assertStringContainsString('3 months', $this->dimension($score, 'emergency_buffer')['explanation']);
    }

    public function test_helping_and_hurting_split_around_the_overall_score(): void
    {
        $score = $this->score('100000.00', '40000.00', '20000.00', '180000.00');

        foreach ($score->helping() as $d) {
            $this->assertGreaterThanOrEqual($score->score, $d->score());
        }
        foreach ($score->hurting() as $d) {
            $this->assertLessThan($score->score, $d->score());
        }
    }

    // --- Recommendations ---------------------------------------------------

    public function test_recommendation_impact_is_reproducible(): void
    {
        $score = $this->score('100000.00', '40000.00', '20000.00', '180000.00');

        $this->assertNotEmpty($score->recommendations);

        foreach ($score->recommendations as $r) {
            // current -> changed metric -> recalculated score -> delta
            $this->assertSame($score->score, $r->currentScore);
            $this->assertSame($r->projectedScore - $r->currentScore, $r->delta());
            $this->assertGreaterThan(0, $r->delta());
        }
    }

    public function test_a_perfect_profile_has_nothing_to_recommend(): void
    {
        $score = $this->score('200000.00', '60000.00', '20000.00', '1000000.00');

        $this->assertSame(100, $score->score);
        $this->assertSame([], $score->recommendations);
    }

    public function test_recommendations_only_target_dimensions_short_of_full_marks(): void
    {
        // EMI and spending are at full marks; only the buffer is short.
        $score = $this->score('100000.00', '40000.00', '20000.00', '60000.00');

        $keys = array_map(fn ($r) => $r->dimensionKey, $score->recommendations);
        $this->assertSame(['emergency_buffer'], $keys);
    }

    public function test_recommendations_are_ordered_by_impact(): void
    {
        $score = $this->score('100000.00', '70000.00', '40000.00', '10000.00');

        $deltas = array_map(fn ($r) => $r->delta(), $score->recommendations);
        $sorted = $deltas;
        rsort($sorted);
        $this->assertSame($sorted, $deltas);
    }

    // --- 12. No prototype values -------------------------------------------

    public function test_no_prototype_dimension_names_survive(): void
    {
        $score = $this->score('100000.00', '40000.00', '20000.00', '180000.00');
        $names = array_map(fn ($d) => $d->name, $score->dimensions);

        // These needed data FynnEdge does not hold and were placeholder copy.
        $this->assertNotContains('Income stability', $names);
        $this->assertNotContains('Credit conduct', $names);
        $this->assertSame(
            ['EMI Burden', 'Spending Control', 'Emergency Buffer'],
            $names,
        );
    }

    // --- 16. Model version -------------------------------------------------

    public function test_the_model_version_is_reported(): void
    {
        $array = $this->score('100000.00', '40000.00', '20000.00', '180000.00')->toArray();

        $this->assertSame('fynnscore-v1', $array['model_version']);
        $this->assertSame(['min' => 0, 'max' => 100], $array['scale']);
    }

    public function test_the_response_states_what_the_score_is_not(): void
    {
        $array = $this->score('100000.00', '40000.00', '20000.00', '180000.00')->toArray();

        $this->assertStringContainsString('not a credit bureau score', $array['disclaimer']);
    }

    public function test_the_emergency_target_comes_from_the_financial_engine(): void
    {
        // One definition of the 6-month target, not two.
        $this->assertSame(
            FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS * 100,
            FynnScoreEngine::EMERGENCY_FULL_MARKS_AT,
        );
    }
}
