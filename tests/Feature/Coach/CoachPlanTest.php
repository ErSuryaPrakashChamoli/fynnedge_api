<?php

namespace Tests\Feature\Coach;

use App\Domain\Consent\ConsentType;
use App\Domain\Intelligence\NextActionRules;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What FynnEdge suggests a customer does next, and what it refuses to.
 *
 * The planner is an action planner, not a salesperson. Every item points at
 * something the customer can do inside FynnEdge, carries a reason they can
 * check, and comes from a figure one of the existing engines produced.
 *
 * The two behaviours worth getting right before anything else are the ones
 * a product under commercial pressure gets wrong: a customer whose finances
 * are in order should be told so rather than handed a task, and a customer
 * spending more than they earn should never be pointed at a loan.
 */
class CoachPlanTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = [], string $name = 'Sample Customer'): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => $name, 'city' => 'Pune']);

        if ($financials !== []) {
            $user->financialProfile->update($financials);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    /** Comfortable: strong score, thick buffer, nothing pending. */
    private function healthy(): array
    {
        return [
            'monthly_income' => 200000,
            'monthly_expenses' => 40000,
            'existing_emi' => 5000,
            'savings' => 900000,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function plan(): array
    {
        return $this->getJson('/api/v1/intelligence')->assertOk()->json('data.plan');
    }

    private function types(): array
    {
        return array_column($this->plan(), 'type');
    }

    private function goal(): void
    {
        $this->postJson('/api/v1/goals', [
            'title' => 'Emergency fund',
            'category' => 'personal',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated();
    }

    // --- The two that matter most -------------------------------------------

    public function test_a_customer_in_good_shape_is_not_handed_an_invented_task(): void
    {
        $this->customer($this->healthy());
        $this->goal();

        $plan = $this->plan();

        /*
         * Nothing is wrong and nothing is pending. The planner may offer
         * something to explore, but it must not manufacture an improvement
         * task to keep somebody opening the app.
         */
        $this->assertNotContains('improve_score', array_column($plan, 'type'));
        $this->assertNotContains('review_buffer', array_column($plan, 'type'));

        // And whatever it does say is an invitation, not an instruction.
        $whole = strtolower(json_encode($plan));
        foreach (['you must', 'you need to', 'act now', 'don\'t miss'] as $pressure) {
            $this->assertStringNotContainsString($pressure, $whole);
        }
    }

    /**
     * @param  bool  $withGoal  changes which rungs the three-item cap reaches
     */
    #[DataProvider('stretchedCustomers')]
    public function test_spending_more_than_you_earn_never_suggests_borrowing(bool $withGoal): void
    {
        $this->customer([
            'monthly_income' => 40000,
            'monthly_expenses' => 45000,
            'existing_emi' => 8000,
            'savings' => 0,
        ]);

        /*
         * Run with and without a goal, deliberately.
         *
         * The first version of this test only covered the no-goal case and
         * passed — not because a rule prevented it, but because the
         * three-item cap stopped the ladder before it reached "explore your
         * options". With a goal set, the cap reaches further and the same
         * customer was being pointed at loan discovery.
         */
        if ($withGoal) {
            $this->goal();
        }

        $plan = $this->plan();
        $whole = strtolower(json_encode($plan));

        /*
         * The single most damaging thing this module could do. A customer
         * whose outgoings exceed their income must never be pointed at a
         * loan, a top-up or a refinance — and a planner that did would be a
         * lead generator wearing a coach's clothes.
         */
        foreach ([
            'borrow', 'loan', 'apply', 'credit', 'refinance', 'top-up',
            'explore your options', 'consolidat',
        ] as $sale) {
            $this->assertStringNotContainsString(
                $sale,
                $whole,
                "suggested [{$sale}] to a customer spending more than they earn",
            );
        }

        // What it does offer is their own figures.
        $this->assertContains('review_profile', array_column($plan, 'type'));

        // And it says what FynnEdge recorded, not what their life is like.
        $shortfall = collect($plan)->firstWhere('type', 'review_profile');
        $this->assertStringContainsString('FynnEdge has recorded', $shortfall['reason']);

        foreach (['overspending', 'living beyond', 'you are spending too'] as $blame) {
            $this->assertStringNotContainsString($blame, $whole);
        }
    }

    /** @return iterable<string, array{int, string}> */
    public static function bufferCoverage(): iterable
    {
        // Outgo is 50,000 a month for this customer.
        yield 'nothing saved' => [0, 'less than a month'];
        yield 'under a month' => [30000, 'less than a month'];
        yield 'exactly a month' => [50000, 'about a month'];
        yield 'a few months' => [125000, 'about 2.5 months'];
    }

    /** @return iterable<string, array{bool}> */
    public static function stretchedCustomers(): iterable
    {
        yield 'with no goal set' => [false];
        yield 'with a goal set, so the cap reaches further' => [true];
    }

    /**
     * The case where the ladder actually reaches "explore your options".
     *
     * The two above pass whether or not the guard in exploreOptions exists,
     * because earlier rungs fill the three slots first — which is exactly
     * how the defect hid in the first place. This customer has a shortfall
     * AND a comfortable buffer, so the buffer rung stays silent and the
     * third slot falls through to exploring options.
     *
     * Removing the guard makes this fail. That is the whole point of it
     * being a separate test.
     */
    public function test_the_ladder_reaches_explore_and_still_refuses_it(): void
    {
        // Outgo 45,000 a month against 40,000 in, but 3,00,000 saved —
        // comfortably past the six-month reference.
        $this->customer([
            'monthly_income' => 40000,
            'monthly_expenses' => 45000,
            'existing_emi' => 0,
            'savings' => 300000,
        ]);
        $this->goal();

        $types = $this->types();

        // The buffer rung is silent, so the ladder ran past it.
        $this->assertNotContains('review_buffer', $types);

        // And still nothing points them at borrowing.
        $this->assertNotContains(
            'explore_options',
            $types,
            'a customer spending more than they earn was pointed at loan options',
        );
    }

    // --- Shape ----------------------------------------------------------------

    public function test_a_plan_is_never_a_backlog(): void
    {
        // A customer with everything wrong at once.
        $this->customer([
            'monthly_income' => 40000,
            'monthly_expenses' => 38000,
            'existing_emi' => 15000,
            'savings' => 0,
        ]);
        $this->postJson('/api/v1/goals', [
            'title' => 'A goal', 'category' => 'personal',
            'target_amount' => 100000,
            'target_date' => now()->addMonths(2)->toDateString(),
        ])->assertCreated();
        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change', 'monthly_change' => -2000,
        ])->assertOk();

        $plan = $this->plan();

        $this->assertLessThanOrEqual(NextActionRules::MAX_ACTIONS, count($plan));
        $this->assertSame(3, NextActionRules::MAX_ACTIONS);
    }

    public function test_the_first_item_is_what_home_shows(): void
    {
        $this->customer($this->healthy());

        $data = $this->getJson('/api/v1/intelligence')->assertOk()->json('data');

        // One ladder, so the plan and the single action cannot disagree.
        $this->assertSame($data['next_action']['type'], $data['plan'][0]['type']);
        $this->assertSame($data['next_action']['reason'], $data['plan'][0]['reason']);
    }

    public function test_the_plan_is_ordered_and_says_so(): void
    {
        $this->customer([
            'monthly_income' => 60000, 'monthly_expenses' => 30000,
            'existing_emi' => 12000, 'savings' => 20000,
        ]);

        $priorities = array_column($this->plan(), 'priority');
        $sorted = $priorities;
        sort($sorted);

        $this->assertSame($sorted, $priorities);
        $this->assertSame(count($priorities), count(array_unique($priorities)));
    }

    public function test_every_action_has_a_reason_and_somewhere_to_go(): void
    {
        foreach ([
            [],
            $this->healthy(),
            ['monthly_income' => 60000, 'monthly_expenses' => 40000,
                'existing_emi' => 20000, 'savings' => 5000],
        ] as $figures) {
            $this->customer($figures);

            foreach ($this->plan() as $action) {
                $this->assertNotEmpty($action['reason'], "[{$action['type']}] has no reason");
                $this->assertStringStartsWith('/', $action['action_route']);
                $this->assertNotEmpty($action['action_label']);
                $this->assertNotEmpty($action['title']);
            }
        }
    }

    // --- Grounded in what FynnEdge actually knows -------------------------------

    public function test_a_customer_with_nothing_entered_is_asked_for_it_honestly(): void
    {
        $this->customer();

        $plan = $this->plan();

        $this->assertSame('complete_profile', $plan[0]['type']);
        $this->assertStringContainsString(
            'cannot work out',
            strtolower($plan[0]['reason']),
        );

        // And no figure is invented for somebody who has entered none.
        $this->assertDoesNotMatchRegularExpression(
            '/₹[0-9]/',
            json_encode($plan),
        );
    }

    public function test_the_buffer_reason_quotes_the_products_own_reference(): void
    {
        $this->customer([
            'monthly_income' => 100000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 60000,
        ]);

        $buffer = collect($this->plan())->firstWhere('type', 'review_buffer');

        $this->assertNotNull($buffer);

        // Six months, FinancialEngine's figure, described as FynnEdge's own.
        $this->assertStringContainsString('6 months', $buffer['reason']);
        $this->assertStringContainsString('FynnEdge measures', $buffer['reason']);
    }

    /**
     * @param  float  $savings  chosen to land either side of a month
     */
    #[DataProvider('bufferCoverage')]
    public function test_the_buffer_reason_reads_like_a_sentence(int $savings, string $expected): void
    {
        $this->customer([
            'monthly_income' => 100000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => $savings,
        ]);

        $buffer = collect($this->plan())->firstWhere('type', 'review_buffer');

        $this->assertNotNull($buffer);
        $this->assertStringContainsString($expected, $buffer['reason']);

        // "about 0 months" is arithmetically right and sounds like a bug.
        $this->assertStringNotContainsString('about 0 months', $buffer['reason']);
        $this->assertStringNotContainsString('about 1 months', $buffer['reason']);
    }

    public function test_a_recorded_exploration_can_be_revisited(): void
    {
        $this->customer($this->healthy());
        $this->goal();

        $this->assertNotContains('review_decision', $this->types());

        // Now they actually explore something.
        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change', 'monthly_change' => 5000,
        ])->assertOk();

        $decision = collect($this->plan())->firstWhere('type', 'review_decision');

        $this->assertNotNull($decision);
        $this->assertSame('/decisions', $decision['action_route']);

        // What they did, never what they wanted.
        $whole = strtolower($decision['title'].' '.$decision['reason']);
        foreach (['considering', 'wanted', 'interested in', 'preferred'] as $inference) {
            $this->assertStringNotContainsString($inference, $whole);
        }
    }

    public function test_a_blocked_customer_is_told_that_before_anything_else(): void
    {
        config([
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $user = $this->customer($this->healthy());
        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'unknown');

        $plan = $this->plan();

        $this->assertSame('resolve_submission_status', $plan[0]['type']);

        // And nothing in the plan invites them to start another.
        $whole = strtolower(json_encode($plan));
        foreach (['apply', 'submit again', 'try another', 'explore your options'] as $contradiction) {
            $this->assertStringNotContainsString($contradiction, $whole);
        }
    }
}
