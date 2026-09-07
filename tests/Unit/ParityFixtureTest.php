<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\ParityCases;
use App\Domain\Money;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Fails when the committed parity fixture no longer matches this engine.
 *
 * The Flutter suite asserts against the same file, so between them a change
 * to either engine that is not reflected in the other cannot pass unnoticed.
 * Regenerate with: php artisan fynn:parity-fixture
 */
class ParityFixtureTest extends TestCase
{
    public function test_the_committed_fixture_matches_the_engine(): void
    {
        $path = __DIR__.'/../fixtures/financial_parity.json';
        $this->assertFileExists($path, 'Run: php artisan fynn:parity-fixture');

        $fixture = json_decode(file_get_contents($path), true);
        $engine = new FinancialEngine;
        $asOf = Carbon::parse(ParityCases::AS_OF);

        $this->assertSame(
            ParityCases::AS_OF,
            $fixture['as_of'],
            'The fixture was generated against a different date',
        );
        $this->assertCount(
            count(ParityCases::all()),
            $fixture['cases'],
            'The fixture is missing cases — regenerate it',
        );

        foreach ($fixture['cases'] as $i => $case) {
            $input = $case['input'];

            $goals = array_map(fn (array $g) => [
                'id' => $g['id'],
                'title' => $g['title'],
                'target' => Money::of($g['target_amount']),
                'saved' => Money::of($g['saved_amount']),
                'target_date' => $asOf->copy()->addDays($g['days_from_as_of']),
            ], $input['goals']);

            $actual = $engine->calculate(
                income: Money::of($input['profile']['monthly_income']),
                expenses: Money::of($input['profile']['monthly_expenses']),
                existingEmi: Money::of($input['profile']['existing_emi']),
                otherObligations: Money::of($input['profile']['other_obligations']),
                savings: Money::of($input['profile']['savings']),
                goals: $goals,
                asOf: $asOf,
            )->toArray();

            $this->assertSame(
                $case['expected'],
                $actual,
                "Case {$case['name']} (index {$i}) has drifted from the fixture",
            );
        }
    }

    public function test_the_matrix_covers_every_case_the_module_requires(): void
    {
        $names = array_column(ParityCases::all(), 'name');

        foreach ([
            'case_1_normal',
            'case_2_zero_income',
            'case_3_zero_emi',
            'case_4_no_other_obligations',
            'case_5_high_obligations',
            'case_6_no_goals',
            'case_7_multiple_goals',
            'case_8_goal_completed',
            'case_9_deadlines',
            'case_10_large_and_precise',
        ] as $required) {
            $this->assertContains($required, $names);
        }
    }
}
