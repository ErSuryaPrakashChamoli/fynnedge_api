<?php

namespace Tests\Feature\Progress;

use App\Domain\Intelligence\FinancialProgress;
use App\Domain\Intelligence\ObservationReason;
use App\Domain\Intelligence\ScoreChangePolicy;
use App\Domain\Score\FynnScoreEngine;
use App\Models\FinancialObservation;
use App\Models\User;
use App\Services\Intelligence\ObservationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 35 — what FynnEdge actually observed changing.
 *
 * Every statement in a customer's progress has to survive four questions:
 * what changed, when, what did FynnEdge know then, and where did the number
 * come from. Anything that cannot answer all four is not history, and the
 * tests below are mostly about the cases where the honest answer is
 * silence.
 *
 * The failure this module is built against is the easy one: taking today's
 * figures, calling them the second half of a comparison, and producing a
 * trend nobody observed. Nothing here compares a stored row with a live
 * reading, and several tests exist only to prove that.
 */
class FinancialProgressTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer']);

        if ($financials !== []) {
            $user->financialProfile->update($financials);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    /** Saves the financial profile the way the app does. */
    private function save(array $figures): void
    {
        $this->putJson('/api/v1/financial-profile', array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'other_obligations' => 0,
            'savings' => 200000,
            'employment_type' => 'salaried',
        ], $figures))->assertOk();
    }

    /** @return array<string, mixed> */
    private function history(): array
    {
        return $this->getJson('/api/v1/intelligence')
            ->assertOk()
            ->json('data.history');
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return $this->history()['items'];
    }

    private function metrics(): array
    {
        return array_column($this->items(), 'metric');
    }

    // --- Nothing observed, nothing said -----------------------------------------

    public function test_a_customer_never_observed_has_no_progress(): void
    {
        $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);

        $history = $this->history();

        $this->assertSame(0, $history['observations']);
        $this->assertFalse($history['has_comparable_history']);
        $this->assertSame([], $history['items']);
    }

    public function test_one_observation_is_a_baseline_and_not_a_trend(): void
    {
        $user = $this->customer();
        $this->save(['monthly_income' => 100000]);

        $this->assertSame(1, $user->financialObservations()->count());

        $history = $this->history();

        /*
         * The critical one. A single stored observation plus the customer's
         * current figures looks like two data points and is not: one is a
         * record and the other is a reading, and pairing them would invent
         * the second half of every comparison.
         */
        $this->assertFalse($history['has_comparable_history']);
        $this->assertSame([], $history['items']);
    }

    public function test_reading_the_customers_own_figures_never_writes_history(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);

        // Everything a customer can look at without changing anything.
        $this->getJson('/api/v1/intelligence')->assertOk();
        $this->getJson('/api/v1/intelligence/timeline')->assertOk();
        $this->getJson('/api/v1/fynn-score')->assertOk();
        $this->getJson('/api/v1/home')->assertOk();
        $this->getJson('/api/v1/financial-summary')->assertOk();
        $this->getJson('/api/v1/goals')->assertOk();

        /*
         * Opening a screen is not a financial event. A table that grew on
         * page views would be a record of somebody's attention rather than
         * their money, and every claim built on it would be about how often
         * they opened an app.
         */
        $this->assertSame(0, $user->financialObservations()->count());
    }

    // --- Two observations, and only then ----------------------------------------

    public function test_two_different_observations_produce_the_change_between_them(): void
    {
        $this->customer();

        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 120000]);

        $income = collect($this->items())->firstWhere('metric', 'monthly_income');

        $this->assertNotNull($income, 'the income change was not reachable');
        $this->assertSame(100000, $income['previous_value']);
        $this->assertSame(120000, $income['current_value']);

        // The exact sentence, in the product's own grouping. number_format
        // would give ₹120,000 where this audience reads ₹1,20,000.
        $this->assertSame(
            'Recorded monthly income changed from ₹1,00,000 to ₹1,20,000.',
            $income['summary'],
        );

        // The customer typed this one; the rest are worked out from it.
        $this->assertSame('customer_provided', $income['source']);
        $this->assertSame('calculated', collect($this->items())
            ->firstWhere('metric', 'surplus')['source']);
    }

    public function test_two_identical_observations_are_one_observation(): void
    {
        $user = $this->customer();

        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 100000]);

        /*
         * Saving the form without changing a figure is a request that
         * succeeded and a financial life that did not move. Three saves used
         * to write three rows, each saying exactly what the row above it
         * said, and a customer with "history" recording nothing happening.
         */
        $this->assertSame(1, $user->financialObservations()->count());
        $this->assertSame([], $this->items());
        $this->assertFalse($this->history()['has_comparable_history']);
    }

    public function test_a_save_that_changes_nothing_does_no_work(): void
    {
        $this->customer();
        $this->save(['monthly_income' => 100000]);

        /** @param  list<string>  $sql */
        $tablesTouchedBy = function (array $figures): string {
            $sql = [];
            \DB::listen(function ($query) use (&$sql) {
                $sql[] = strtolower($query->sql);
            });

            $this->save($figures);

            return implode(' ', $sql);
        };

        $unchanged = $tablesTouchedBy(['monthly_income' => 100000]);

        /*
         * A request that moves no figure should not do the work of one that
         * does: no row lock taken on the notification watermark to decide
         * whether a band changed, and nothing written to the history.
         *
         * Asserted on what the request actually touched rather than on a
         * query count, because a count only says the guard did something
         * and this says what.
         */
        $this->assertStringNotContainsString('notification_watermarks', $unchanged);
        $this->assertStringNotContainsString('financial_observations', $unchanged);

        // And a real change does all of it, so the absence above is the
        // guard rather than a path nothing ever takes.
        $changed = $tablesTouchedBy(['monthly_income' => 130000]);

        $this->assertStringContainsString('notification_watermarks', $changed);
        $this->assertStringContainsString('financial_observations', $changed);
    }

    public function test_a_state_returned_to_is_observed_again(): void
    {
        $user = $this->customer();

        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 120000]);
        $this->save(['monthly_income' => 100000]);

        /*
         * Not a duplicate. The row before it is different, so this is a new
         * fact about the customer — they changed their income and changed
         * it back, and both movements happened.
         */
        $this->assertSame(3, $user->financialObservations()->count());

        $income = array_values(array_filter(
            $this->items(),
            fn (array $i) => $i['metric'] === 'monthly_income',
        ));

        $this->assertCount(2, $income);

        // Newest first, and each names the pair it came from.
        $this->assertSame(120000, $income[0]['previous_value']);
        $this->assertSame(100000, $income[0]['current_value']);
        $this->assertSame(100000, $income[1]['previous_value']);
        $this->assertSame(120000, $income[1]['current_value']);
    }

    public function test_three_observations_produce_two_comparisons(): void
    {
        $this->customer();

        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 110000]);
        $this->save(['monthly_income' => 130000]);

        $income = array_values(array_filter(
            $this->items(),
            fn (array $i) => $i['metric'] === 'monthly_income',
        ));

        // Consecutive pairs, never the first against the last: FynnEdge
        // observed two steps, not one jump.
        $this->assertCount(2, $income);
        $this->assertSame([110000, 130000], [
            $income[0]['previous_value'],
            $income[0]['current_value'],
        ]);
        $this->assertSame([100000, 110000], [
            $income[1]['previous_value'],
            $income[1]['current_value'],
        ]);
    }

    // --- The score --------------------------------------------------------------

    public function test_a_score_change_names_both_scores_and_neither_cause(): void
    {
        $this->customer();

        // A thin buffer, then a thick one: the score moves well past the
        // threshold the product already uses for mentioning it.
        $this->save(['savings' => 0]);
        $this->save(['savings' => 600000]);

        $score = collect($this->items())->firstWhere('metric', 'fynn_score');

        $this->assertNotNull($score, 'the score change was not reachable');
        $this->assertStringStartsWith('FynnScore changed from', $score['summary']);

        // Both figures are rows FynnEdge wrote down.
        $observed = FinancialObservation::orderBy('id')->pluck('score')->all();
        $this->assertSame($observed[0], $score['previous_value']);
        $this->assertSame($observed[1], $score['current_value']);

        // Never a cause. Several figures move between two observations and
        // the model averages three of them.
        $whole = strtolower(json_encode($score));
        foreach (['because', 'caused', 'due to', 'thanks to', 'as a result'] as $claim) {
            $this->assertStringNotContainsString($claim, $whole);
        }
    }

    public function test_a_score_arriving_is_not_a_score_changing(): void
    {
        $this->customer();

        // No income, so no score. Then an income, so a score.
        $this->save(['monthly_income' => 0, 'monthly_expenses' => 20000]);
        $this->save(['monthly_income' => 100000, 'monthly_expenses' => 20000]);

        $observed = FinancialObservation::orderBy('id')->get();
        $this->assertNull($observed[0]->score);
        $this->assertNotNull($observed[1]->score);

        /*
         * A score becoming available is an arrival, and Module 31 already
         * records it as a moment on the same screen. Presented as a change
         * it would have to be a change from something, and there was
         * nothing.
         */
        $this->assertNotContains('fynn_score', $this->metrics());
    }

    public function test_a_score_move_smaller_than_the_products_threshold_is_not_reported(): void
    {
        $this->customer();

        // Outgo is ₹50,000 a month, so this moves the buffer from 4 to
        // 4.2 months and the overall score by a single point.
        $this->save(['savings' => 200000]);
        $this->save(['savings' => 210000]);

        $observed = FinancialObservation::orderBy('id')->pluck('score')->all();
        $delta = abs($observed[1] - $observed[0]);

        $this->assertLessThan(ScoreChangePolicy::POINTS, $delta);
        $this->assertGreaterThan(0, $delta, 'the score did not move at all');

        // The threshold the product already defines for exactly this
        // question. Module 35 introduces no second one.
        $this->assertNotContains('fynn_score', $this->metrics());
    }

    public function test_a_score_is_never_compared_across_model_versions(): void
    {
        $user = $this->customer();

        $this->save(['savings' => 0]);
        $this->save(['savings' => 600000]);

        $this->assertContains('fynn_score', $this->metrics());

        // The same two rows, one of them produced by a different model.
        $user->financialObservations()->orderBy('id')->first()
            ->update(['score_model_version' => 'fynnscore-v2']);

        /*
         * A score from a different model is a different measurement, and
         * subtracting one from the other gives a number that means nothing.
         * A customer told their score moved because FynnEdge changed how it
         * counts has been misled.
         */
        $this->assertNotContains('fynn_score', $this->metrics());
    }

    public function test_a_score_change_explains_itself_from_what_was_observed(): void
    {
        $this->customer();

        $this->save(['savings' => 0]);
        $this->save(['savings' => 600000]);

        $score = collect($this->items())->firstWhere('metric', 'fynn_score');
        $components = collect($score['components']);
        $buffer = $components->firstWhere('key', 'emergency_buffer');

        $this->assertNotNull($buffer);

        // The figures that were true when each score was calculated, and
        // what the model made of them — its own published curve, applied to
        // the stored ratios rather than to today's profile.
        $observed = FinancialObservation::orderBy('id')->get();
        $this->assertEquals(
            (float) $observed[0]->emergency_months,
            $buffer['previous_value'],
        );
        $this->assertEquals(
            (float) $observed[1]->emergency_months,
            $buffer['current_value'],
        );
        $this->assertLessThan($buffer['current_score'], $buffer['previous_score']);

        // Only the components that moved. One that did not is not part of
        // what changed, and listing it invites the reader to connect it.
        $this->assertNotContains(
            'emi_burden',
            $components->pluck('key')->all(),
        );

        /*
         * And the same rule one level up. Only the savings changed here, so
         * the income and the surplus are the same figure at both ends —
         * identical values are not a change, however much else moved.
         */
        $this->assertNotContains('monthly_income', $this->metrics());
        $this->assertNotContains('surplus', $this->metrics());
        $this->assertContains('emergency_months', $this->metrics());
    }

    public function test_a_historical_score_explanation_does_not_move_with_todays_figures(): void
    {
        $this->customer();

        $this->save(['savings' => 0]);
        $this->save(['savings' => 600000]);

        $before = collect($this->items())->firstWhere('metric', 'fynn_score');

        // Everything about the customer's present changes.
        $this->save([
            'monthly_income' => 30000,
            'monthly_expenses' => 28000,
            'existing_emi' => 12000,
            'savings' => 0,
        ]);
        $this->postJson('/api/v1/goals', [
            'title' => 'A goal', 'category' => 'personal',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        /*
         * The whole point of storing an observation. A historical score and
         * the figures that produced it are what FynnEdge knew then, and
         * nothing the customer does later may edit that — a history that
         * moved with the present would not be a history.
         *
         * Compared whole rather than by timestamp: several observations can
         * land inside one second, so the item itself is the identity.
         */
        $this->assertContains(
            $before,
            $this->items(),
            'the earlier score change was rewritten by a later edit',
        );
    }

    // --- Zero, absent, and the difference ---------------------------------------

    public function test_a_first_recorded_income_is_not_a_rise_from_nothing(): void
    {
        $this->customer();

        $this->save(['monthly_income' => 0, 'monthly_expenses' => 0, 'savings' => 0]);
        $this->save(['monthly_income' => 100000, 'monthly_expenses' => 0, 'savings' => 0]);

        /*
         * Zero and absent are different facts. A customer who had entered
         * nothing must not appear in their own history as somebody who
         * earned nothing and then started earning.
         */
        $this->assertNotContains('monthly_income', $this->metrics());
        $this->assertNotContains('surplus', $this->metrics());
    }

    public function test_a_buffer_measured_against_no_outgo_is_not_a_figure(): void
    {
        $this->customer();

        /*
         * Four months of cover, and then nothing recorded going out at all.
         *
         * Ratio::timesOver returns zero for a zero denominator, so the
         * second observation stores 0 months — and read as a fact that says
         * this customer's buffer collapsed, when what actually happened is
         * that there is no longer anything for it to cover. Module 34 found
         * the same zero being read the same way on the plan.
         *
         * The customer's savings did not move between these two.
         */
        $this->save([
            'monthly_income' => 80000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'other_obligations' => 0,
            'savings' => 200000,
        ]);
        $this->save([
            'monthly_income' => 80000, 'monthly_expenses' => 0,
            'existing_emi' => 0, 'other_obligations' => 0,
            'savings' => 200000,
        ]);

        $observed = FinancialObservation::orderBy('id')->get();
        $this->assertEquals(4, $observed[0]->emergency_months);
        $this->assertEquals(0, $observed[1]->emergency_months);
        $this->assertEquals(0, $observed[1]->monthly_outgo);

        $this->assertNotContains(
            'emergency_months',
            $this->metrics(),
            'a buffer with nothing to cover was reported as having collapsed',
        );

        // The outgo itself did move, and that is a fact worth stating.
        $this->assertContains('monthly_outgo', $this->metrics());
    }

    public function test_a_surplus_crossing_zero_is_stated_as_two_figures(): void
    {
        $this->customer();

        $this->save([
            'monthly_income' => 40000, 'monthly_expenses' => 45000,
            'existing_emi' => 3000, 'savings' => 0,
        ]);
        $this->save([
            'monthly_income' => 70000, 'monthly_expenses' => 45000,
            'existing_emi' => 3000, 'savings' => 0,
        ]);

        $surplus = collect($this->items())->firstWhere('metric', 'surplus');

        $this->assertNotNull($surplus);
        $this->assertSame(-8000, $surplus['previous_value']);
        $this->assertSame(22000, $surplus['current_value']);

        // The sign leads the symbol. A shortfall printed as ₹8,000 is not a
        // formatting nicety, it is the wrong number.
        $this->assertSame('-₹8,000', $surplus['previous_display']);

        // And nothing interprets it.
        $whole = strtolower(json_encode($this->history()));
        foreach ([
            'recovered', 'healthier', 'stronger', 'weaker', 'worsening',
            'on track', 'falling behind', 'doing great', 'well done',
        ] as $interpretation) {
            $this->assertStringNotContainsString($interpretation, $whole);
        }
    }

    /**
     * A shortfall that improved is still a shortfall.
     *
     * The safety rule Module 33 exists for, applied to history: a customer
     * whose surplus moved from -₹12,000 to -₹5,000 has improved and still
     * cannot cover their life, and nothing about the improvement may be
     * turned into a reason to borrow.
     */
    public function test_an_improving_shortfall_still_produces_no_borrowing(): void
    {
        $this->customer();

        $this->save([
            'monthly_income' => 40000, 'monthly_expenses' => 45000,
            'existing_emi' => 7000, 'savings' => 0,
        ]);
        $this->save([
            'monthly_income' => 40000, 'monthly_expenses' => 42000,
            'existing_emi' => 3000, 'savings' => 0,
        ]);

        $surplus = collect($this->items())->firstWhere('metric', 'surplus');
        $this->assertSame('increased', $surplus['direction']);
        $this->assertSame(-5000, $surplus['current_value']);

        $whole = strtolower(json_encode(
            $this->getJson('/api/v1/intelligence')->assertOk()->json('data')
        ));

        foreach ([
            'borrow', 'loan', 'credit card', 'top-up', 'refinance',
            'consolidat', 'explore your options',
        ] as $sale) {
            $this->assertStringNotContainsString(
                $sale,
                $whole,
                "offered [{$sale}] to a customer still spending more than they earn",
            );
        }
    }

    // --- What history is not ----------------------------------------------------

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function readOnlyActions(): iterable
    {
        yield 'a Twin simulation' => ['/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change', 'monthly_change' => -5000,
        ]];

        yield 'a Twin saving scenario' => ['/api/v1/fynn-twin/simulate', [
            'type' => 'saving_change', 'monthly_amount' => 20000, 'months' => 12,
        ]];
    }

    #[DataProvider('readOnlyActions')]
    public function test_a_simulation_is_not_a_financial_observation(
        string $uri,
        array $payload,
    ): void {
        $user = $this->customer();
        $this->save(['monthly_income' => 100000]);

        $before = $user->financialObservations()->count();

        $this->postJson($uri, $payload)->assertOk();

        /*
         * A customer trying a scenario has not changed their savings, their
         * income or anything else. Module 32 records the exploration as a
         * decision, which is a different kind of thing in a different table
         * — and turning it into financial history would say a life changed
         * because somebody moved a slider.
         */
        $this->assertSame($before, $user->financialObservations()->count());
        $this->assertSame([], $this->items());
    }

    public function test_comparing_products_is_not_a_financial_observation(): void
    {
        $user = $this->customer();
        $this->save(['monthly_income' => 100000]);

        $before = $user->financialObservations()->count();

        $this->getJson('/api/v1/products?purpose=business')->assertOk();
        $this->getJson('/api/v1/fynn-match?amount=500000&tenure_months=36&purpose=business')
            ->assertOk();

        $this->assertSame($before, $user->financialObservations()->count());
    }

    public function test_decision_history_and_financial_history_stay_separate(): void
    {
        $this->customer();
        $this->save(['monthly_income' => 100000]);
        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change', 'monthly_change' => -5000,
        ])->assertOk();

        $decisions = $this->getJson('/api/v1/decisions')->assertOk()->json('data');
        $this->assertNotEmpty($decisions, 'the exploration was not recorded at all');

        // In the other table, and only there.
        $this->assertSame([], $this->items());
    }

    // --- Shape and safety -------------------------------------------------------

    public function test_history_is_bounded_by_the_products_existing_limit(): void
    {
        $this->customer();

        for ($i = 0; $i < 30; $i++) {
            $this->save(['monthly_income' => 100000 + ($i * 1000)]);
        }

        $items = $this->items();

        $this->assertLessThanOrEqual(FinancialProgress::MAX_CHANGES, count($items));
        $this->assertSame(20, FinancialProgress::MAX_CHANGES);
        $this->assertNotEmpty($items);
    }

    public function test_progress_is_chronological_and_deterministic(): void
    {
        $this->customer();

        for ($i = 0; $i < 4; $i++) {
            $this->save(['monthly_income' => 100000 + ($i * 5000)]);
        }

        $first = $this->items();
        $second = $this->items();

        // Same request, same answer. Nothing here depends on database order.
        $this->assertSame($first, $second);

        $timestamps = array_column($first, 'observed_at');
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    public function test_the_newest_pair_comes_first_and_the_score_leads_it(): void
    {
        $this->customer();

        $this->save(['monthly_income' => 100000, 'savings' => 50000]);
        $this->save(['monthly_income' => 120000, 'savings' => 300000]);

        $metrics = $this->metrics();

        /*
         * Pairs have an order in time; what moved inside one moment does
         * not. Reversing the flattened list put each pair's items backwards
         * too, so a customer whose score moved read the buffer change first
         * and the score last.
         */
        $this->assertSame('fynn_score', $metrics[0]);
        $this->assertContains('monthly_income', $metrics);
        $this->assertLessThan(
            array_search('emergency_months', $metrics, true),
            array_search('fynn_score', $metrics, true),
        );
    }

    public function test_history_carries_nothing_sensitive(): void
    {
        $this->customer();
        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 120000]);

        $body = strtolower(json_encode($this->history()));

        foreach ([
            'aadhaar', 'pan_number', 'account_number', 'ifsc', 'storage_key',
            'vault', 'kyc', 'token', 'provider_payload', 'idempotency',
            'user_id', 'customer_id', 'state_digest', 'follows_id', '"id"',
            'reason',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "leaked: {$leak}");
        }
    }

    public function test_history_is_strictly_the_callers_own(): void
    {
        $other = $this->customer();
        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 900000]);
        $this->assertNotEmpty($this->items());

        $mine = $this->customer();
        $this->save(['monthly_income' => 50000]);
        $this->save(['monthly_income' => 60000]);

        $body = json_encode($this->items());

        $this->assertStringNotContainsString('900000', $body);
        $this->assertStringContainsString('60000', $body);
        $this->assertNotSame($other->getKey(), $mine->getKey());
    }

    public function test_a_client_cannot_write_its_own_history(): void
    {
        $user = $this->customer();
        $this->save(['monthly_income' => 100000]);

        // Everything a client might try to assert about its own past.
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 120000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'other_obligations' => 0,
            'savings' => 200000,
            'employment_type' => 'salaried',

            'observed_at' => now()->subYear()->toIso8601String(),
            'previous_value' => 1,
            'current_value' => 99999999,
            'fynn_score' => 100,
            'surplus' => 5000000,
            'history' => [['metric' => 'fynn_score', 'current_value' => 100]],
        ])->assertOk();

        $observations = $user->financialObservations()->orderBy('id')->get();

        $this->assertCount(2, $observations);

        foreach ($observations as $observation) {
            // Server time, server figures. Nothing the client sent reached
            // a column.
            $this->assertTrue($observation->observed_at->isToday());
            $this->assertNotEquals(5000000, $observation->surplus);
            $this->assertNotSame(100, $observation->score);
        }

        $income = collect($this->items())->firstWhere('metric', 'monthly_income');
        $this->assertSame(120000, $income['current_value']);
    }

    public function test_an_observation_is_never_written_for_a_failed_change(): void
    {
        $user = $this->customer();
        $this->save(['monthly_income' => 100000]);

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => -50000,
            'monthly_expenses' => 40000,
        ])->assertStatus(422);

        // The mutation did not happen, so nothing about it was true to
        // write down.
        $this->assertSame(1, $user->financialObservations()->count());
        $this->assertEquals(
            100000,
            $user->financialObservations()->first()->monthly_income,
        );
    }

    public function test_the_history_query_does_not_grow_with_the_customers_life(): void
    {
        $count = function (int $saves, int $goals): int {
            $this->customer();

            for ($i = 0; $i < $saves; $i++) {
                $this->save(['monthly_income' => 100000 + ($i * 3000)]);
            }

            for ($i = 0; $i < $goals; $i++) {
                $this->postJson('/api/v1/goals', [
                    'title' => "Goal {$i}", 'category' => 'personal',
                    'target_amount' => 100000,
                    'target_date' => now()->addMonths(6 + $i)->toDateString(),
                ])->assertCreated();
            }

            $queries = 0;
            \DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->getJson('/api/v1/intelligence')->assertOk();

            return $queries;
        };

        /*
         * One bounded read of the observations, whatever is in them. The
         * changes are arithmetic over rows already in hand, so more history
         * is more comparing and not more queries.
         */
        $small = $count(2, 1);

        $this->assertGreaterThan(5, $small);
        $this->assertSame($small, $count(12, 9));
    }

    // --- Goals ------------------------------------------------------------------

    public function test_a_goal_has_a_present_but_no_observed_history(): void
    {
        $user = $this->customer(['monthly_income' => 100000, 'monthly_expenses' => 40000]);

        $id = $this->postJson('/api/v1/goals', [
            'title' => 'Emergency fund', 'category' => 'personal',
            'target_amount' => 300000, 'saved_amount' => 75000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/goals/{$id}", [
            'title' => 'Emergency fund', 'category' => 'personal',
            'target_amount' => 300000, 'saved_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertOk();

        /*
         * FynnEdge observes the financial profile, not each goal's amounts.
         * The row holds ₹1,00,000 and the previous value is gone, so
         * "you saved ₹25,000 more" is a sentence FynnEdge cannot support —
         * and the honest thing is not to have a goal-progress metric at all
         * rather than one built from a value nobody stored.
         */
        $this->assertNotContains('goal_progress', $this->metrics());
        $this->assertNotContains('saved_amount', $this->metrics());

        $body = strtolower(json_encode($this->history()));
        $this->assertStringNotContainsString('75,000', $body);
        $this->assertStringNotContainsString('a month', $body);

        // The goal's present is intact and comes from where it always did.
        $this->assertSame(
            100000,
            $this->getJson('/api/v1/goals')->assertOk()->json('data.0.saved_amount'),
        );

        $this->assertSame(1, $user->goals()->count());
    }

    // --- Concurrency ------------------------------------------------------------

    public function test_the_same_state_written_twice_at_once_is_one_observation(): void
    {
        $user = $this->customer();
        $recorder = app(ObservationRecorder::class);

        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
        ]);

        // Two callers deciding at the same moment that something happened,
        // both describing the same financial state. The unique index settles
        // it; a read-then-write would have a window between its statements.
        $recorder->observe($user, ObservationReason::FinancialProfileChanged);
        $recorder->observe($user, ObservationReason::GoalChanged);
        $recorder->observe($user, ObservationReason::ApplicationMilestone);

        $this->assertSame(1, $user->financialObservations()->count());
    }

    public function test_the_chain_of_observations_stays_ordered(): void
    {
        $user = $this->customer();

        $this->save(['monthly_income' => 100000]);
        $this->save(['monthly_income' => 110000]);
        $this->save(['monthly_income' => 120000]);

        $observations = $user->financialObservations()->orderBy('id')->get();

        // Each follows the one before it, and the first follows nothing.
        $this->assertSame(0, $observations[0]->follows_id);
        $this->assertSame($observations[0]->getKey(), $observations[1]->follows_id);
        $this->assertSame($observations[1]->getKey(), $observations[2]->follows_id);

        $digests = $observations->pluck('state_digest');
        $this->assertCount(3, $digests->unique());
        $this->assertEmpty($digests->filter(fn ($d) => $d === ''));
    }

    public function test_the_score_model_is_the_only_score_model(): void
    {
        $this->customer();
        $this->save(['savings' => 0]);
        $this->save(['savings' => 600000]);

        $observations = FinancialObservation::orderBy('id')->get();

        foreach ($observations as $observation) {
            $this->assertSame(
                FynnScoreEngine::MODEL_VERSION,
                $observation->score_model_version,
            );
        }
    }
}
