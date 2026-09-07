<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Home\AttentionCases;
use App\Domain\Home\AttentionItem;
use App\Domain\Home\AttentionRules;
use App\Domain\Money;
use App\Models\FinancialGoal;
use Tests\TestCase;

/**
 * Fails when the committed attention fixture no longer matches these rules.
 *
 * The Flutter suite asserts against the same file, so between them a change
 * to either implementation that is not reflected in the other cannot pass
 * unnoticed. Regenerate with: php artisan fynn:parity-fixture
 */
class AttentionFixtureTest extends TestCase
{
    public function test_the_committed_fixture_matches_the_rules(): void
    {
        $path = __DIR__.'/../fixtures/home_attention_parity.json';
        $this->assertFileExists($path, 'Run: php artisan fynn:parity-fixture');

        $fixture = json_decode(file_get_contents($path), true);

        $this->assertSame(
            FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
            $fixture['affordability_reference_percent'],
        );
        $this->assertSame(
            FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS,
            $fixture['emergency_fund_target_months'],
        );
        $this->assertCount(
            count(AttentionCases::all()),
            $fixture['cases'],
            'The fixture is missing cases — regenerate it',
        );

        $engine = app(FinancialEngine::class);
        $rules = app(AttentionRules::class);

        foreach ($fixture['cases'] as $case) {
            $p = $case['input']['profile'];

            $goals = collect($case['input']['goals'])->map(
                fn (array $g) => new FinancialGoal([
                    'title' => $g['title'],
                    'category' => 'other',
                    'target_amount' => $g['target_amount'],
                    'saved_amount' => $g['saved_amount'],
                    'target_date' => $g['target_date'],
                    'status' => 'active',
                ])
            );

            $snapshot = $engine->calculate(
                income: Money::of($p['monthly_income']),
                expenses: Money::of($p['monthly_expenses']),
                existingEmi: Money::of($p['existing_emi']),
                otherObligations: Money::of($p['other_obligations']),
                savings: Money::of($p['savings']),
            );

            $actual = array_map(
                fn (AttentionItem $item) => $item->toArray(),
                $rules->evaluate($snapshot, $goals, collect()),
            );

            $this->assertSame(
                $case['expected'],
                $actual,
                "Case {$case['name']} has drifted from the fixture",
            );
        }
    }

    public function test_the_cases_cover_every_rule_and_both_sides_of_each(): void
    {
        $names = array_column(AttentionCases::all(), 'name');

        foreach ([
            'comfortable customer',
            'no income',
            'spending more than earned',
            'emi exactly on the reference',
            'emi one point past the reference',
            'buffer exactly six months',
            'overdue goal only',
            'goal met but past its date',
        ] as $required) {
            $this->assertContains($required, $names);
        }
    }
}
