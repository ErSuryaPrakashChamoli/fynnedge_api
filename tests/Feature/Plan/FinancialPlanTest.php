<?php

namespace Tests\Feature\Plan;

use App\Domain\Consent\ConsentType;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Models\FinancialGoal;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 34 — the customer's financial plan.
 *
 * A plan in FynnEdge is a reading, not a record. It is where the customer's
 * figures currently stand, the goals they chose, and the one step the
 * existing action ladder produced — all from one read of one state. There
 * is no plan table, no plan endpoint and no plan engine, and these tests
 * assert that as directly as they assert the sentences.
 *
 * The rules worth being strict about are the ones a product under
 * commercial pressure gets wrong: never suggesting a loan to somebody who
 * cannot cover their current life, never turning a calculated monthly
 * amount into a commitment the customer is held to, and never claiming a
 * figure for somebody who has entered none.
 */
class FinancialPlanTest extends TestCase
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

    /** @return array<string, mixed> */
    private function position(): array
    {
        return $this->getJson('/api/v1/intelligence')
            ->assertOk()
            ->json('data.position');
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

    /** Straight to the model, so a past date can exist as it does in life. */
    private function goalFor(User $user, array $attributes = []): FinancialGoal
    {
        return $user->goals()->create(array_merge([
            'title' => 'Emergency fund',
            'category' => 'personal',
            'target_amount' => 300000,
            'saved_amount' => 120000,
            'target_date' => now()->addMonths(18)->toDateString(),
            'status' => 'active',
        ], $attributes));
    }

    // --- Shape: what was and was not built --------------------------------------

    public function test_no_plan_is_persisted(): void
    {
        /*
         * A plan derived on read cannot go stale, cannot race a goal update
         * and cannot outlive the condition that produced it. A stored one
         * would: "complete your financial profile", written to a table,
         * keeps being true to the table after it stopped being true of the
         * customer.
         */
        $this->assertFalse(Schema::hasTable('financial_plans'));
        $this->assertFalse(Schema::hasTable('plans'));
        $this->assertFalse(Schema::hasTable('plan_items'));
        $this->assertFalse(Schema::hasTable('goal_commitments'));
    }

    public function test_no_second_endpoint_describes_the_same_customer(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->all();

        foreach ($uris as $uri) {
            $this->assertStringNotContainsString('financial-plan', $uri);
        }

        // Because the position rides the intelligence read that already
        // happened. A separate request would describe the same customer at
        // a different instant and could contradict the action beside it.
        $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);
        $data = $this->getJson('/api/v1/intelligence')->assertOk()->json('data');

        $this->assertArrayHasKey('position', $data);
        $this->assertArrayHasKey('next_action', $data);
        $this->assertArrayHasKey('plan', $data);
    }

    public function test_the_buffer_reference_is_the_engines_and_not_a_new_one(): void
    {
        $this->customer(['monthly_income' => 80000, 'monthly_expenses' => 40000]);

        $this->assertSame(
            FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS,
            $this->position()['emergency_reference_months'],
        );
        $this->assertSame(6, FinancialEngine::EMERGENCY_FUND_TARGET_MONTHS);
    }

    // --- The position -----------------------------------------------------------

    /** @return iterable<string, array{array<string, int>, string, string}> */
    public static function positions(): iterable
    {
        yield 'income above outgo' => [
            ['monthly_income' => 100000, 'monthly_expenses' => 40000, 'existing_emi' => 20000],
            'positive',
            'Your recorded income is higher than your monthly outgo.',
        ];

        yield 'income exactly meets outgo' => [
            ['monthly_income' => 60000, 'monthly_expenses' => 40000, 'existing_emi' => 20000],
            'matched',
            'Your recorded income currently matches your monthly outgo.',
        ];

        yield 'outgo above income' => [
            ['monthly_income' => 40000, 'monthly_expenses' => 45000, 'existing_emi' => 8000],
            'negative',
            'Your recorded monthly outgo is higher than your recorded income.',
        ];
    }

    #[DataProvider('positions')]
    public function test_the_position_says_what_was_recorded(
        array $financials,
        string $state,
        string $statement,
    ): void {
        $this->customer($financials);

        $position = $this->position();

        $this->assertTrue($position['is_measurable']);
        $this->assertSame($state, $position['surplus_state']);
        $this->assertSame($statement, $position['statement']);

        /*
         * "Recorded" is doing the work in every one of those sentences.
         * FynnEdge knows what it was told; it does not know what somebody
         * earns, and the commonest cause of a shortfall on this screen is a
         * figure that moved without the profile being updated.
         */
        $this->assertStringContainsString('recorded', strtolower($statement));

        foreach (['too much', 'overspending', 'you should', 'you must', 'well done'] as $judgement) {
            $this->assertStringNotContainsString($judgement, strtolower($statement));
        }
    }

    public function test_a_customer_with_no_income_is_given_no_figures(): void
    {
        $this->customer();

        $position = $this->position();

        $this->assertFalse($position['is_measurable']);
        $this->assertSame('unknown', $position['surplus_state']);
        $this->assertStringContainsString('no monthly income', $position['statement']);

        // Not even as zero. A zero surplus is a claim about somebody's
        // money; an absent one is the truth here.
        $this->assertNull($position['emergency_months']);
        $this->assertNull($position['emergency_statement']);
        $this->assertFalse($position['emergency_below_reference']);

        /*
         * The money fields stay zero, and `is_measurable` is what gates
         * them.
         *
         * Deliberately the same convention FinancialSnapshot already uses
         * for `has_income`, which the app has parsed since Module 4 — one
         * rule for "there is no figure here" across the product rather than
         * a second one invented for this block. The screen renders none of
         * them without the flag, which test/plan_test.dart asserts.
         */
        $this->assertSame(0, $position['surplus']);
        $this->assertSame(0, $position['monthly_outgo']);
    }

    /**
     * The buffer question can have no answer.
     *
     * Ratio::timesOver returns zero for a zero denominator, and reading
     * that as a fact told a customer with nothing recorded going out that
     * their savings would cover less than a month. That customer is
     * reachable: the financial profile form accepts an income on its own.
     */
    public function test_a_customer_with_nothing_going_out_is_not_told_their_buffer_is_thin(): void
    {
        $this->customer([
            'monthly_income' => 80000,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'other_obligations' => 0,
            'savings' => 0,
        ]);

        $position = $this->position();

        $this->assertTrue($position['is_measurable']);
        $this->assertNull($position['emergency_months']);
        $this->assertNull($position['emergency_statement']);
        $this->assertFalse($position['emergency_below_reference']);

        // And the ladder stays quiet about it rather than inventing a
        // shortfall out of a missing denominator.
        $this->assertNotContains('review_buffer', $this->types());
        $this->assertStringNotContainsString(
            'less than a month',
            json_encode($this->plan()),
        );
    }

    /** @return iterable<string, array{int, bool, string}> */
    public static function buffers(): iterable
    {
        // Outgo is 50,000 a month for this customer.
        yield 'nothing saved' => [0, true, 'less than a month'];
        yield 'exactly a month' => [50000, true, 'about a month'];
        yield 'two and a half months' => [125000, true, 'about 2.5 months'];
        yield 'exactly the reference' => [300000, false, 'about 6 months'];
        yield 'well past the reference' => [600000, false, 'about 12 months'];
    }

    #[DataProvider('buffers')]
    public function test_the_buffer_is_measured_against_the_products_reference(
        int $savings,
        bool $below,
        string $wording,
    ): void {
        $this->customer([
            'monthly_income' => 90000,
            'monthly_expenses' => 30000,
            'existing_emi' => 20000,
            'savings' => $savings,
        ]);

        $position = $this->position();

        $this->assertSame($below, $position['emergency_below_reference']);
        $this->assertStringContainsString($wording, $position['emergency_statement']);
        $this->assertStringContainsString('FynnEdge measures against 6 months', $position['emergency_statement']);

        // Never an instruction, and never a figure to raise immediately.
        foreach (['you need', 'you must', 'immediately', 'urgently'] as $push) {
            $this->assertStringNotContainsString(
                $push,
                strtolower($position['emergency_statement']),
            );
        }

        // The rung and the plan header are the same sentence about the same
        // customer, because both read one position.
        $buffer = collect($this->plan())->firstWhere('type', 'review_buffer');

        if ($below) {
            $this->assertNotNull($buffer, 'the buffer rung was not reachable');
            $this->assertSame($position['emergency_statement'], $buffer['reason']);
        } else {
            $this->assertNull($buffer);
        }
    }

    // --- Goals ------------------------------------------------------------------

    public function test_goal_figures_come_from_the_engine(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);
        $goal = $this->goalFor($user, [
            'target_amount' => 300000,
            'saved_amount' => 120000,
            'target_date' => now()->addMonths(18)->toDateString(),
        ]);

        $derived = $this->getJson('/api/v1/goals')->assertOk()->json('data.0.derived');
        $metrics = app(FinancialEngine::class)->goalMetrics($goal->fresh());

        // Not recomputed anywhere: the resource repeats what the engine
        // produced, to the paise.
        $this->assertSame($metrics->progress(), $derived['progress']);
        $this->assertSame($metrics->monthsRemaining, $derived['months_remaining']);
        $this->assertSame($metrics->monthlyRequired->toJsonNumber(), $derived['monthly_required']);
        $this->assertSame($metrics->remaining->toJsonNumber(), $derived['remaining_amount']);
        $this->assertSame(0.4, $derived['progress']);
    }

    public function test_a_reached_goal_is_derived_and_not_a_status(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);
        $this->goalFor($user, ['target_amount' => 90000, 'saved_amount' => 90000]);

        $goal = $this->getJson('/api/v1/goals')->assertOk()->json('data.0');

        $this->assertTrue($goal['derived']['is_complete']);
        $this->assertEquals(1.0, $goal['derived']['progress']);
        $this->assertSame(0, $goal['derived']['remaining_amount']);
        $this->assertSame(0, $goal['derived']['monthly_required']);

        /*
         * The status column is untouched, and deliberately.
         *
         * There is no completed state in the goal model and Module 34 does
         * not add one: completion is the two amounts meeting, which is
         * true or false at every read. A status would be a second answer
         * that could disagree with the figures printed beside it.
         */
        $this->assertSame('active', $goal['status']);
    }

    public function test_a_goal_past_its_date_is_stated_not_scored(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);
        $this->goalFor($user, ['target_date' => now()->subMonth()->toDateString()]);

        $goal = $this->getJson('/api/v1/goals')->assertOk()->json('data.0');

        $this->assertTrue($goal['derived']['is_overdue']);
        $this->assertSame(0, $goal['derived']['months_remaining']);

        // The whole remainder is what is left, which is what an overdue
        // goal means. Nothing calls it a failure.
        $this->assertSame(180000, $goal['derived']['monthly_required']);

        $whole = strtolower(json_encode($goal));
        foreach (['failed', 'missed', 'behind', 'at risk'] as $verdict) {
            $this->assertStringNotContainsString($verdict, $whole);
        }
    }

    public function test_goals_are_ordered_by_the_date_they_are_due(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);
        $this->goalFor($user, ['title' => 'Later', 'target_date' => now()->addMonths(30)->toDateString()]);
        $this->goalFor($user, ['title' => 'Sooner', 'target_date' => now()->addMonths(4)->toDateString()]);
        $this->goalFor($user, ['title' => 'Middle', 'target_date' => now()->addMonths(12)->toDateString()]);

        $titles = array_column(
            $this->getJson('/api/v1/goals')->assertOk()->json('data'),
            'title',
        );

        /*
         * Nearest first, which is the ordering the Goals module already
         * established. Nothing here infers which goal matters most to the
         * customer — that would be a preference FynnEdge was never told.
         */
        $this->assertSame(['Sooner', 'Middle', 'Later'], $titles);
    }

    public function test_a_goal_only_changes_when_the_customer_changes_it(): void
    {
        $user = $this->customer([
            'monthly_income' => 40000,
            'monthly_expenses' => 45000,
            'savings' => 0,
        ]);
        $goal = $this->goalFor($user);
        $before = [
            'target_amount' => $goal->target_amount,
            'target_date' => $goal->target_date->toDateString(),
            'saved_amount' => $goal->saved_amount,
            'status' => $goal->status,
        ];

        // A simulation, a score read and the plan itself — none of which is
        // a customer editing their goal.
        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change', 'monthly_change' => -5000,
        ])->assertOk();
        $this->getJson('/api/v1/fynn-score')->assertOk();
        $this->getJson('/api/v1/intelligence')->assertOk();

        $after = $goal->fresh();
        $this->assertSame($before, [
            'target_amount' => $after->target_amount,
            'target_date' => $after->target_date->toDateString(),
            'saved_amount' => $after->saved_amount,
            'status' => $after->status,
        ]);
    }

    public function test_an_application_does_not_become_a_goal(): void
    {
        config(['fynnedge.lender.driver' => 'fixture']);

        $user = $this->customer([
            'monthly_income' => 200000, 'monthly_expenses' => 40000, 'savings' => 900000,
        ]);
        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');

        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated();

        $this->assertSame(0, $user->goals()->count());
        $this->assertSame([], $this->getJson('/api/v1/goals')->assertOk()->json('data'));
    }

    // --- Safety -----------------------------------------------------------------

    public function test_a_shortfall_is_never_answered_with_borrowing(): void
    {
        $user = $this->customer([
            'monthly_income' => 40000,
            'monthly_expenses' => 45000,
            'existing_emi' => 8000,
            'savings' => 0,
        ]);
        $this->goalFor($user);

        // The whole plan payload, position and actions together — the two
        // halves a customer reads as one screen.
        $whole = strtolower(json_encode(
            $this->getJson('/api/v1/intelligence')->assertOk()->json('data')
        ));

        foreach ([
            'borrow', 'loan', 'credit card', 'top-up', 'refinance',
            'consolidat', 'explore your options', 'overdraft',
        ] as $sale) {
            $this->assertStringNotContainsString(
                $sale,
                $whole,
                "the plan suggested [{$sale}] to a customer spending more than they earn",
            );
        }

        $this->assertContains('review_profile', $this->types());
    }

    public function test_nothing_in_the_plan_promises_the_future(): void
    {
        $user = $this->customer([
            'monthly_income' => 90000, 'monthly_expenses' => 35000,
            'existing_emi' => 10000, 'savings' => 60000,
        ]);
        $this->goalFor($user);

        $whole = strtolower(json_encode(
            $this->getJson('/api/v1/intelligence')->assertOk()->json('data')
        ));

        /*
         * A plan describes what is; it does not say what will be. "On
         * track" is the one to watch, because it reads as encouragement
         * and is in fact a prediction about a customer's next eighteen
         * months that nothing in FynnEdge can support.
         */
        foreach ([
            'on track', 'you will reach', "you'll reach", 'guaranteed',
            'by march', 'will improve', 'is likely to', 'projected to',
            'financially secure', 'your plan will work',
        ] as $promise) {
            $this->assertStringNotContainsString($promise, $whole);
        }
    }

    public function test_the_monthly_amount_is_never_described_as_a_commitment(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);
        $this->goalFor($user);

        $whole = strtolower(json_encode([
            $this->getJson('/api/v1/goals')->assertOk()->json('data'),
            $this->getJson('/api/v1/intelligence')->assertOk()->json('data'),
        ]));

        /*
         * monthly_required is remaining / months, recalculated at every
         * read. FynnEdge holds no commitment model, so it may not describe
         * one — and the customer who lowers their target has not broken a
         * promise, because there was none to break.
         */
        foreach ([
            'you committed', 'your commitment', 'you agreed', 'required emi',
            'mandatory', 'you owe', 'you must save', 'you promised',
        ] as $obligation) {
            $this->assertStringNotContainsString($obligation, $whole);
        }
    }

    public function test_client_supplied_figures_never_reach_the_plan(): void
    {
        $user = $this->customer([
            'monthly_income' => 40000, 'monthly_expenses' => 45000, 'savings' => 0,
        ]);

        // Everything a client might try to assert about itself.
        $this->postJson('/api/v1/goals', [
            'title' => 'Tampered', 'category' => 'personal',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => 10000,

            // None of these has a validation rule, so none reaches a model.
            'user_id' => $user->getKey() + 1,
            'status' => 'completed',
            'progress' => 1,
            'monthly_required' => 1,
            'surplus' => 500000,
            'emergency_months' => 99,
            'fynn_score' => 850,
        ])->assertCreated();

        $goal = $user->goals()->firstOrFail();
        $this->assertSame($user->getKey(), $goal->user_id);
        $this->assertSame('active', $goal->status);

        $position = $this->position();
        $this->assertSame('negative', $position['surplus_state']);
        $this->assertSame(-5000, $position['surplus']);
        $this->assertNotSame(99, $position['emergency_months']);
    }

    public function test_a_plan_carries_nothing_sensitive(): void
    {
        $user = $this->customer([
            'monthly_income' => 90000, 'monthly_expenses' => 35000, 'savings' => 60000,
        ]);
        $this->goalFor($user);

        $body = $this->getJson('/api/v1/intelligence')->assertOk()->getContent();

        foreach ([
            'aadhaar', 'pan_number', 'account_number', 'ifsc', 'storage_key',
            'vault', 'kyc', 'token', 'password', 'provider_payload',
            'user_id', 'customer_id',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, strtolower($body));
        }
    }

    // --- The matrix -------------------------------------------------------------

    /** @return iterable<string, array{array<string, int>, string, list<string>}> */
    public static function customerStates(): iterable
    {
        yield 'nothing entered' => [[], 'none', ['complete_profile']];

        yield 'income only' => [
            ['monthly_income' => 60000],
            'none',
            [],
        ];

        yield 'complete profile, no goals' => [
            ['monthly_income' => 100000, 'monthly_expenses' => 40000, 'savings' => 500000],
            'none',
            ['create_goal'],
        ];

        yield 'zero surplus' => [
            ['monthly_income' => 60000, 'monthly_expenses' => 40000, 'existing_emi' => 20000, 'savings' => 400000],
            'none',
            [],
        ];

        yield 'negative surplus' => [
            ['monthly_income' => 40000, 'monthly_expenses' => 45000, 'savings' => 0],
            'one',
            ['review_profile'],
        ];

        yield 'healthy buffer' => [
            ['monthly_income' => 200000, 'monthly_expenses' => 40000, 'savings' => 900000],
            'one',
            [],
        ];

        yield 'thin buffer' => [
            ['monthly_income' => 90000, 'monthly_expenses' => 50000, 'savings' => 20000],
            'one',
            ['review_buffer'],
        ];

        yield 'several goals' => [
            ['monthly_income' => 120000, 'monthly_expenses' => 50000, 'savings' => 400000],
            'several',
            [],
        ];

        yield 'a reached goal' => [
            ['monthly_income' => 120000, 'monthly_expenses' => 50000, 'savings' => 400000],
            'reached',
            [],
        ];

        yield 'a goal past its date' => [
            ['monthly_income' => 120000, 'monthly_expenses' => 50000, 'savings' => 400000],
            'overdue',
            [],
        ];
    }

    /**
     * Every state a customer can be in, and the plan stays a plan.
     *
     * Deliberately not asserting one exact ladder per state — that would
     * restate the implementation. What it asserts is what must hold
     * whatever the state: at most three items, every one explained and
     * reachable, ordered, honest about absent figures, and never a promise.
     *
     * @param  list<string>  $expected  rungs that must be present
     */
    #[DataProvider('customerStates')]
    public function test_every_customer_state_produces_an_honest_plan(
        array $financials,
        string $goals,
        array $expected,
    ): void {
        $user = $this->customer($financials);

        match ($goals) {
            'one' => $this->goalFor($user),
            'several' => collect([4, 12, 30])->each(
                fn (int $m) => $this->goalFor($user, [
                    'title' => "Goal {$m}",
                    'target_date' => now()->addMonths($m)->toDateString(),
                ])
            ),
            'reached' => $this->goalFor($user, ['target_amount' => 90000, 'saved_amount' => 90000]),
            'overdue' => $this->goalFor($user, ['target_date' => now()->subMonth()->toDateString()]),
            default => null,
        };

        $data = $this->getJson('/api/v1/intelligence')->assertOk()->json('data');
        $plan = $data['plan'];
        $position = $data['position'];

        $this->assertLessThanOrEqual(3, count($plan));

        foreach ($expected as $type) {
            $this->assertContains(
                $type,
                array_column($plan, 'type'),
                "[{$type}] was not reachable for this customer",
            );
        }

        $priorities = array_column($plan, 'priority');
        $sorted = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities);

        foreach ($plan as $action) {
            $this->assertNotEmpty($action['reason']);
            $this->assertStringStartsWith('/', $action['action_route']);
        }

        // The plan and the single action are the same ladder.
        $this->assertSame(
            $data['next_action']['type'] ?? null,
            $plan[0]['type'] ?? null,
        );

        // No figure exists for a customer who entered none.
        if (! $position['is_measurable']) {
            $this->assertDoesNotMatchRegularExpression('/₹[0-9]/', json_encode($data));
        }

        // Never a promise, in any state.
        $whole = strtolower(json_encode($data));
        foreach (['on track', 'you will reach', 'guaranteed'] as $promise) {
            $this->assertStringNotContainsString($promise, $whole);
        }
    }

    public function test_an_incomplete_profile_outranks_goals_and_products(): void
    {
        $user = $this->customer();
        $this->goalFor($user);

        $types = $this->types();

        $this->assertSame('complete_profile', $types[0]);
        $this->assertNotContains('explore_options', $types);
    }

    /**
     * The rung's own rule, finally true of the code.
     *
     * exploreOptions is documented as `goals.any and applications.empty`
     * and its sentence reads "see what suits the goal you set" — but it
     * checked only the applications, so a customer with no goal at all was
     * pointed at loan discovery against a goal that did not exist.
     *
     * The customer here is built so the ladder actually reaches that rung:
     * a thick buffer keeps the buffer rung silent, a strong score keeps the
     * improvement rung silent, and nothing is in flight. Without them the
     * three-item cap would stop the ladder first and this test would pass
     * whether or not the guard existed — which is exactly how the last one
     * of these hid.
     */
    public function test_a_customer_with_no_goal_is_not_offered_products_against_one(): void
    {
        $this->customer([
            'monthly_income' => 200000,
            'monthly_expenses' => 40000,
            'existing_emi' => 5000,
            'savings' => 900000,
        ]);

        $types = $this->types();

        // The earlier rungs are silent, so the ladder ran past them.
        $this->assertNotContains('review_buffer', $types);
        $this->assertNotContains('improve_score', $types);

        // It reached the bottom of the ladder, so the rung was asked.
        $this->assertContains('review_score', $types);

        $this->assertContains('create_goal', $types);
        $this->assertNotContains(
            'explore_options',
            $types,
            'a customer with no goal was offered products to suit it',
        );
    }

    public function test_a_blocked_application_outranks_the_whole_plan(): void
    {
        config([
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $user = $this->customer([
            'monthly_income' => 200000, 'monthly_expenses' => 40000, 'savings' => 900000,
        ]);
        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');
        $this->goalFor($user);

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $this->assertSame('resolve_submission_status', $this->types()[0]);
    }

    // --- Cost -------------------------------------------------------------------

    public function test_the_goals_list_costs_the_same_however_many_there_are(): void
    {
        $count = function (int $goals): int {
            $user = $this->customer([
                'monthly_income' => 120000, 'monthly_expenses' => 50000,
            ]);

            for ($i = 0; $i < $goals; $i++) {
                $this->goalFor($user, [
                    'title' => "Goal {$i}",
                    'target_date' => now()->addMonths(6 + $i)->toDateString(),
                ]);
            }

            $queries = 0;
            \DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->getJson('/api/v1/goals')->assertOk();

            return $queries;
        };

        /*
         * Each card's progress, months and monthly figure are arithmetic
         * over two columns already in the row. A resource that asked the
         * engine for a fresh read per goal would turn the Goals screen into
         * one query per card.
         */
        $one = $count(1);

        $this->assertGreaterThan(0, $one);
        $this->assertSame($one, $count(9));
    }

    public function test_the_plan_costs_the_same_however_many_goals_there_are(): void
    {
        $count = function (int $goals): int {
            $user = $this->customer([
                'monthly_income' => 120000, 'monthly_expenses' => 50000, 'savings' => 100000,
            ]);

            for ($i = 0; $i < $goals; $i++) {
                $this->goalFor($user, [
                    'title' => "Goal {$i}",
                    'target_date' => now()->addMonths(6 + $i)->toDateString(),
                ]);
            }

            $queries = 0;
            \DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->getJson('/api/v1/intelligence')->assertOk();

            return $queries;
        };

        /*
         * One read of one state, and the goals come with it.
         *
         * Each goal's progress, months and monthly figure are derived from
         * the two amounts already in the row, so more goals is more
         * arithmetic and not more queries. A card that fetched its own
         * metrics would turn a plan into one query per goal.
         */
        $one = $count(1);
        $nine = $count(9);

        // Non-zero, or the comparison above is two nothings agreeing.
        $this->assertGreaterThan(5, $one);
        $this->assertSame($one, $nine);
    }
}
