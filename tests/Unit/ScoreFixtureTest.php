<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\ParityCases;
use App\Domain\Money;
use App\Domain\Score\FynnScoreEngine;
use App\Domain\Score\ScoreCases;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Fails when the committed FynnScore fixture no longer matches this engine.
 * The Flutter suite asserts against the same file.
 */
class ScoreFixtureTest extends TestCase
{
    public function test_the_committed_fixture_matches_the_engine(): void
    {
        $path = __DIR__.'/../fixtures/fynnscore_parity.json';
        $this->assertFileExists($path, 'Run: php artisan fynn:parity-fixture');

        $fixture = json_decode(file_get_contents($path), true);
        $financial = new FinancialEngine;
        $scoring = new FynnScoreEngine;
        $asOf = Carbon::parse(ParityCases::AS_OF);

        $this->assertSame(FynnScoreEngine::MODEL_VERSION, $fixture['model_version']);
        $this->assertCount(count(ScoreCases::all()), $fixture['cases']);

        foreach ($fixture['cases'] as $case) {
            $p = $case['input']['profile'];

            $goals = array_map(fn (array $g) => [
                'id' => $g['id'],
                'title' => $g['title'],
                'target' => Money::of($g['target_amount']),
                'saved' => Money::of($g['saved_amount']),
                'target_date' => $asOf->copy()->addDays($g['days_from_as_of']),
            ], $case['input']['goals']);

            $snapshot = $financial->calculate(
                income: Money::of($p['monthly_income']),
                expenses: Money::of($p['monthly_expenses']),
                existingEmi: Money::of($p['existing_emi']),
                otherObligations: Money::of($p['other_obligations']),
                savings: Money::of($p['savings']),
                goals: $goals,
                asOf: $asOf,
            );

            $this->assertSame(
                $case['expected'],
                $scoring->score($snapshot)->toArray(),
                "Case {$case['name']} has drifted from the fixture",
            );
        }
    }

    public function test_the_two_goal_cases_share_a_profile_and_a_score(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../fixtures/fynnscore_parity.json'),
            true,
        );

        $byName = array_column($fixture['cases'], null, 'name');
        $strong = $byName['strong_goal_progress'];
        $weak = $byName['weak_goal_progress'];

        // Identical finances, very different goals...
        $this->assertSame($strong['input']['profile'], $weak['input']['profile']);
        $this->assertNotSame($strong['input']['goals'], $weak['input']['goals']);

        // ...and therefore an identical score.
        $this->assertSame($strong['expected'], $weak['expected']);
    }

    public function test_the_matrix_covers_every_required_situation(): void
    {
        $names = array_column(ScoreCases::all(), 'name');

        foreach ([
            'healthy_profile', 'high_emi_burden', 'high_spending_ratio',
            'low_emergency_savings', 'negative_surplus',
            'strong_goal_progress', 'weak_goal_progress', 'zero_income',
            'all_zero_profile', 'incomplete_profile',
        ] as $required) {
            $this->assertContains($required, $names);
        }
    }
}
