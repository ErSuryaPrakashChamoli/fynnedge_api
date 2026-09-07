<?php

namespace Tests\Feature\Credit;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductSource;
use App\Domain\Credit\CreditBureau;
use App\Domain\Credit\CreditProfile;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Match\CriterionStatus;
use App\Domain\Match\FynnMatchEngine;
use App\Domain\Match\MatchCategory;
use App\Domain\Match\MatchCriterion;
use App\Domain\Match\MatchRequest;
use App\Domain\Money;
use App\Domain\Provider\UnsupportedRequirement;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A documented minimum credit score, against what FynnEdge knows.
 *
 * The whole question this module exists to answer correctly: a requirement
 * that could not be checked must never read as one that was met.
 */
class CreditCriterionTest extends TestCase
{
    private function product(?int $minCreditScore): LoanProduct
    {
        return new LoanProduct(
            id: 'test:product',
            lender: 'Sample Lender',
            name: 'Test Loan',
            lenderTag: '',
            category: 'personal',
            minAmount: Money::of(100000),
            maxAmount: Money::of(2000000),
            interestRate: 11.5,
            minTenureMonths: 12,
            maxTenureMonths: 60,
            processingFeePercent: 1.0,
            processingFeeCap: null,
            fynnTrust: 80,
            prepaymentCharge: '',
            suitedReasons: [],
            considerations: [],
            conditions: [],
            trustBreakdown: [],
            minMonthlyIncome: Money::of(30000),
            maxAgeAtMaturity: 60,
            minAge: 21,
            provider: null,
            source: ProductSource::sample(),
            minCreditScore: $minCreditScore,
        );
    }

    private function profile(int $score): CreditProfile
    {
        return new CreditProfile(
            bureau: CreditBureau::Fixture,
            score: $score,
            scoreModel: 'SAMPLE-SCORE-V1',
            scoreMin: 300,
            scoreMax: 900,
            obtainedAt: Carbon::parse('2026-09-06T10:00:00+05:30'),
        );
    }

    private function evaluate(?int $requirement, ?CreditProfile $credit): array
    {
        $engine = app(FynnMatchEngine::class);
        $financial = app(FinancialEngine::class);

        $snapshot = $financial->calculate(
            income: Money::of(150000),
            expenses: Money::of(40000),
            existingEmi: Money::of(10000),
            otherObligations: Money::zero(),
            savings: Money::of(600000),
        );

        $match = $engine->evaluate(
            $this->product($requirement),
            new MatchRequest(
                amount: Money::of(500000),
                tenureMonths: 48,
                purpose: 'personal',
            ),
            $snapshot,
            34,
            $credit,
        );

        $criterion = collect($match->criteria)
            ->first(fn (MatchCriterion $c) => $c->key === 'credit_score');

        return [$match, $criterion];
    }

    #[DataProvider('creditMatrix')]
    public function test_the_credit_criterion_matrix(
        ?int $requirement,
        ?int $score,
        ?CriterionStatus $expectedStatus,
        MatchCategory $expectedCategory,
    ): void {
        [$match, $criterion] = $this->evaluate(
            $requirement,
            $score === null ? null : $this->profile($score),
        );

        if ($expectedStatus === null) {
            $this->assertNull(
                $criterion,
                'a product that publishes no minimum should carry no credit '
                .'criterion at all',
            );
        } else {
            $this->assertNotNull($criterion);
            $this->assertSame($expectedStatus, $criterion->status);
        }

        $this->assertSame(
            $expectedCategory,
            $match->category,
            'the documented mapping from criterion state to match category '
            .'was not followed',
        );
    }

    /**
     * The documented mapping, in full.
     *
     * The third row is the one that matters. No bureau report means the
     * requirement was not evaluated, which is Good Match under the existing
     * semantics — "the rules passed, but something could not be checked" —
     * and never Strong, which would say every documented rule was met.
     */
    public static function creditMatrix(): array
    {
        return [
            'no requirement, no report' => [
                null, null, null, MatchCategory::Strong,
            ],
            'no requirement, with a report' => [
                null, 742, null, MatchCategory::Strong,
            ],
            'requirement met' => [
                700, 742, CriterionStatus::Passed, MatchCategory::Strong,
            ],
            'requirement met exactly' => [
                700, 700, CriterionStatus::Passed, MatchCategory::Strong,
            ],
            'requirement not met' => [
                700, 598, CriterionStatus::Failed, MatchCategory::NotAMatch,
            ],
            'one point short' => [
                700, 699, CriterionStatus::Failed, MatchCategory::NotAMatch,
            ],
            'requirement could not be checked' => [
                700, null, CriterionStatus::Unavailable, MatchCategory::Good,
            ],
        ];
    }

    public function test_a_product_with_no_requirement_needs_no_bureau_at_all(): void
    {
        // Introducing credit must not make every product depend on a pull.
        [$withoutCredit] = $this->evaluate(null, null);
        [$withCredit] = $this->evaluate(null, $this->profile(742));

        $this->assertEquals(
            array_map(fn (MatchCriterion $c) => $c->toArray(), $withoutCredit->criteria),
            array_map(fn (MatchCriterion $c) => $c->toArray(), $withCredit->criteria),
        );
        $this->assertSame($withoutCredit->category, $withCredit->category);
    }

    public function test_an_unchecked_requirement_never_reads_as_met(): void
    {
        [, $criterion] = $this->evaluate(700, null);

        $this->assertSame(CriterionStatus::Unavailable, $criterion->status);
        $this->assertStringContainsString('cannot tell you', $criterion->detail);

        // The wording must not suggest the requirement was satisfied.
        foreach (['meets', 'satisfied', 'passed', 'eligible'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase(
                $word,
                $criterion->detail,
            );
        }
    }

    public function test_meeting_the_requirement_is_never_described_as_approval(): void
    {
        [$match, $criterion] = $this->evaluate(700, $this->profile(742));

        $this->assertSame(CriterionStatus::Passed, $criterion->status);

        $text = json_encode($match->toArray());
        foreach ([
            'approved', 'pre-approved', 'guaranteed', 'sanctioned',
            'you will get', 'eligible for approval',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase(
                $claim,
                $text,
                "a met credit requirement was described as: {$claim}",
            );
        }

        // What it does say: the documented figure, and the customer's.
        $this->assertStringContainsString('742', $criterion->detail);
        $this->assertStringContainsString('700', $criterion->detail);
    }

    public function test_a_strong_fynn_score_does_not_rescue_a_failed_requirement(): void
    {
        // FynnScore is FynnEdge's view of financial health; a bureau minimum
        // is a provider's documented requirement. A customer can be in
        // excellent financial health and still not meet one.
        [$match, $criterion] = $this->evaluate(700, $this->profile(598));

        $this->assertSame(CriterionStatus::Failed, $criterion->status);
        $this->assertSame(MatchCategory::NotAMatch, $match->category);
    }

    public function test_requirements_fynnedge_still_cannot_evaluate_stay_unsupported(): void
    {
        // Module 22's boundary is unchanged for everything except the
        // minimum score. A business vintage requirement is still something
        // FynnEdge holds no data for, and it must not become a criterion.
        $requirement = new UnsupportedRequirement('business_vintage_months', '36');

        $this->assertSame(
            ['key' => 'business_vintage_months', 'value' => '36'],
            $requirement->toArray(),
        );

        [$match] = $this->evaluate(null, null);

        $keys = array_map(fn (MatchCriterion $c) => $c->key, $match->criteria);
        $this->assertNotContains('business_vintage_months', $keys);
        $this->assertNotContains('vintage', $keys);
    }
}
