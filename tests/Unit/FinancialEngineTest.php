<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\ParityCases;
use App\Domain\Money;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * The engine tested on its own — no HTTP, no database, no container.
 */
class FinancialEngineTest extends TestCase
{
    private FinancialEngine $engine;

    private Carbon $asOf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new FinancialEngine;
        $this->asOf = Carbon::parse(ParityCases::AS_OF);
    }

    /** @return array<string, string> */
    private function profile(array $attributes = []): array
    {
        return array_merge([
            'monthly_income' => '100000.00',
            'monthly_expenses' => '40000.00',
            'existing_emi' => '20000.00',
            'other_obligations' => '0.00',
            'savings' => '180000.00',
        ], $attributes);
    }

    /** @return array<string, mixed> */
    private function goal(string $target, string $saved, int $days): array
    {
        return [
            'id' => '1',
            'title' => 'Test goal',
            'target' => Money::of($target),
            'saved' => Money::of($saved),
            'target_date' => $this->asOf->copy()->addDays($days),
        ];
    }

    /** @param list<array<string, mixed>> $goals */
    private function snapshot(array $profile, array $goals = [])
    {
        return $this->engine->calculate(
            income: Money::of($profile['monthly_income']),
            expenses: Money::of($profile['monthly_expenses']),
            existingEmi: Money::of($profile['existing_emi']),
            otherObligations: Money::of($profile['other_obligations']),
            savings: Money::of($profile['savings']),
            goals: $goals,
            asOf: $this->asOf,
        );
    }

    /** @param array<string, mixed> $goal */
    private function metrics(array $goal)
    {
        return $this->engine->metricsFor(
            $goal['id'],
            $goal['title'],
            $goal['target'],
            $goal['saved'],
            $goal['target_date'],
            $this->asOf,
        );
    }

    public function test_the_headline_definitions(): void
    {
        $s = $this->snapshot($this->profile());

        // outgo = expenses + emi + obligations
        $this->assertSame('60000.00', $s->totalOutgo->toDecimalString());
        // surplus = income - outgo
        $this->assertSame('40000.00', $s->surplus->toDecimalString());
        $this->assertSame(20, $s->emiRatio->toJsonNumber());
        $this->assertSame(40, $s->expenseRatio->toJsonNumber());
        $this->assertSame(60, $s->outgoRatio->toJsonNumber());
        $this->assertSame(3, $s->emergencyMonths->toJsonNumber());
    }

    public function test_zero_income_never_divides_by_zero(): void
    {
        $s = $this->snapshot($this->profile(['monthly_income' => '0.00']));

        $this->assertSame(0, $s->emiRatio->toJsonNumber());
        $this->assertSame(0, $s->expenseRatio->toJsonNumber());
        $this->assertSame(0, $s->outgoRatio->toJsonNumber());
        $this->assertFalse($s->hasIncome);
        // Surplus is still meaningful, and negative.
        $this->assertSame('-60000.00', $s->surplus->toDecimalString());
    }

    public function test_zero_outgo_never_divides_by_zero(): void
    {
        $s = $this->snapshot($this->profile([
            'monthly_expenses' => '0.00',
            'existing_emi' => '0.00',
            'other_obligations' => '0.00',
        ]));

        $this->assertSame(0, $s->emergencyMonths->toJsonNumber());
        $this->assertSame('100000.00', $s->surplus->toDecimalString());
    }

    public function test_zero_emi_reads_as_zero_ratio_not_as_missing(): void
    {
        $s = $this->snapshot($this->profile(['existing_emi' => '0.00']));

        $this->assertSame(0, $s->emiRatio->toJsonNumber());
        $this->assertSame('40000.00', $s->totalOutgo->toDecimalString());
    }

    public function test_other_obligations_reduce_the_surplus(): void
    {
        $s = $this->snapshot($this->profile(['other_obligations' => '10000.00']));

        $this->assertSame('70000.00', $s->totalOutgo->toDecimalString());
        $this->assertSame('30000.00', $s->surplus->toDecimalString());
    }

    public function test_a_negative_surplus_is_reported_not_clamped(): void
    {
        $s = $this->snapshot($this->profile([
            'monthly_income' => '50000.00',
            'monthly_expenses' => '45000.00',
            'existing_emi' => '15000.00',
        ]));

        // Spending more than you earn is a fact worth showing.
        $this->assertTrue($s->surplus->isNegative());
        $this->assertSame('-10000.00', $s->surplus->toDecimalString());
    }

    public function test_goal_metrics(): void
    {
        $m = $this->metrics($this->goal('1500000.00', '320000.00', 540));

        $this->assertSame('1180000.00', $m->remaining->toDecimalString());
        $this->assertSame(0.2133, $m->progress());
        $this->assertSame(18, $m->monthsRemaining);
        $this->assertSame('65555.56', $m->monthlyRequired->toDecimalString());
        $this->assertFalse($m->isComplete);
        $this->assertFalse($m->isOverdue);
    }

    public function test_a_completed_goal_needs_nothing_more(): void
    {
        $m = $this->metrics($this->goal('500000.00', '500000.00', 200));

        $this->assertSame('0.00', $m->remaining->toDecimalString());
        $this->assertSame(1.0, $m->progress());
        $this->assertSame('0.00', $m->monthlyRequired->toDecimalString());
        $this->assertTrue($m->isComplete);
    }

    public function test_an_overfunded_goal_clamps_rather_than_going_negative(): void
    {
        $m = $this->metrics($this->goal('100000.00', '150000.00', 200));

        $this->assertSame('0.00', $m->remaining->toDecimalString());
        $this->assertSame(1.0, $m->progress(), 'progress is clamped to 1');
        $this->assertTrue($m->isComplete);
    }

    public function test_a_zero_target_has_no_progress_and_does_not_throw(): void
    {
        $m = $this->metrics($this->goal('0.00', '0.00', 100));

        $this->assertSame(0.0, $m->progress());
        $this->assertFalse($m->isComplete, 'an empty goal is not complete');
    }

    public function test_deadline_arithmetic(): void
    {
        // Exactly 30 days is one month; 31 rounds up to two.
        $this->assertSame(1, $this->engine->monthsUntil(
            $this->asOf->copy()->addDays(30), $this->asOf,
        ));
        $this->assertSame(2, $this->engine->monthsUntil(
            $this->asOf->copy()->addDays(31), $this->asOf,
        ));
        // Today and the past are both "due now".
        $this->assertSame(0, $this->engine->monthsUntil($this->asOf, $this->asOf));
        $this->assertSame(0, $this->engine->monthsUntil(
            $this->asOf->copy()->subDays(30), $this->asOf,
        ));
    }

    public function test_an_overdue_goal_owes_the_whole_remainder_now(): void
    {
        $m = $this->metrics($this->goal('100000.00', '10000.00', -30));

        $this->assertSame(0, $m->monthsRemaining);
        $this->assertSame('90000.00', $m->monthlyRequired->toDecimalString());
        $this->assertTrue($m->isOverdue);
    }

    public function test_large_values_keep_full_precision(): void
    {
        $s = $this->snapshot($this->profile([
            'monthly_income' => '9999999.99',
            'monthly_expenses' => '1234567.89',
            'existing_emi' => '987654.32',
            'other_obligations' => '111111.11',
            'savings' => '87654321.00',
        ]));

        // Exact to the paisa: a float would not manage this.
        $this->assertSame('2333333.32', $s->totalOutgo->toDecimalString());
        $this->assertSame('7666666.67', $s->surplus->toDecimalString());
    }

    public function test_aggregate_goal_figures(): void
    {
        $goals = [
            $this->goal('360000.00', '180000.00', 365),
            $this->goal('1500000.00', '320000.00', 540),
        ];

        $s = $this->snapshot($this->profile(), $goals);

        $this->assertCount(2, $s->goals);
        $this->assertSame('1860000.00', $s->goalsTarget->toDecimalString());
        $this->assertSame('500000.00', $s->goalsSaved->toDecimalString());
        $this->assertSame('1360000.00', $s->goalsRemaining->toDecimalString());
        // The sum of the per-goal figures, not a recalculation.
        $this->assertSame('79401.71', $s->goalsMonthlyRequired->toDecimalString());
    }

    public function test_no_goals_still_yields_a_valid_snapshot(): void
    {
        $s = $this->snapshot($this->profile());

        $this->assertSame([], $s->goals);
        $this->assertTrue($s->goalsTarget->isZero());
        $this->assertTrue($s->goalsMonthlyRequired->isZero());
    }

    public function test_a_missing_profile_yields_zeroes_not_an_error(): void
    {
        $s = $this->engine->calculate(
            income: Money::zero(),
            expenses: Money::zero(),
            existingEmi: Money::zero(),
            otherObligations: Money::zero(),
            savings: Money::zero(),
            asOf: $this->asOf,
        );

        $this->assertTrue($s->income->isZero());
        $this->assertTrue($s->surplus->isZero());
        $this->assertFalse($s->hasIncome);
    }

    public function test_it_is_deterministic(): void
    {
        $first = $this->snapshot($this->profile(), [
            $this->goal('1500000.00', '320000.00', 540),
        ]);

        $second = $this->snapshot($this->profile(), [
            $this->goal('1500000.00', '320000.00', 540),
        ]);

        $this->assertSame($first->toArray(), $second->toArray());
    }

    public function test_every_metric_has_a_stated_definition(): void
    {
        $definitions = FinancialEngine::definitions();

        foreach ([
            'monthly_outgo', 'surplus', 'emi_ratio', 'expense_ratio',
            'outgo_ratio', 'emergency_months', 'goal_remaining',
            'goal_progress', 'goal_months_remaining', 'goal_monthly_required',
            'rounding',
        ] as $key) {
            $this->assertArrayHasKey($key, $definitions);
            $this->assertNotEmpty($definitions[$key]);
        }
    }

    public function test_the_emergency_fund_target_is_six_months_of_outgo(): void
    {
        $target = $this->engine->emergencyFundTarget(Money::of('60000.00'));
        $this->assertSame('360000.00', $target->toDecimalString());
    }
}
