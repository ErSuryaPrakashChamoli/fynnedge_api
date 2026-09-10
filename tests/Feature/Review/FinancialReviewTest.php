<?php

namespace Tests\Feature\Review;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Domain\Score\FynnScoreEngine;
use App\Models\FinancialGoal;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\Intelligence\FinancialIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Module 36 — the financial review.
 *
 * A review is a gathering, not an engine. Everything on it already existed:
 * the position is Module 34's, the score is FynnScoreEngine's, the goals are
 * the Goals module's own metrics, the changes are Module 35's observations
 * and the next step is Module 33's ladder.
 *
 * So most of what is worth testing is that there is still exactly one of
 * each. A review that scored a customer, ranked their goals or decided its
 * own next step would be a second opinion about somebody's money, and the
 * two opinions would disagree the first time either changed.
 *
 * The other half is that asking for a review changes nothing. A read that
 * wrote an observation would make opening a screen part of somebody's
 * financial history.
 */
class FinancialReviewTest extends TestCase
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

    private function healthy(): array
    {
        return [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 200000,
        ];
    }

    /** @return array<string, mixed> */
    private function review(): array
    {
        return $this->getJson('/api/v1/intelligence')->assertOk()->json('data');
    }

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

    private function save(array $figures): void
    {
        $this->putJson('/api/v1/financial-profile', array_merge(
            $this->healthy(),
            ['other_obligations' => 0, 'employment_type' => 'salaried'],
            $figures,
        ))->assertOk();
    }

    // --- One of each ------------------------------------------------------------

    public function test_the_review_and_the_score_screen_report_one_score(): void
    {
        $this->customer($this->healthy());

        $review = $this->review()['score'];
        $screen = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');

        /*
         * The critical assertion of this module. FynnScoreEngine runs once
         * when the intelligence state is built, and the review reads that
         * result — it does not fetch the score screen's answer and it does
         * not compute a second one.
         */
        $this->assertSame($screen['score'], $review['score']);
        $this->assertSame($screen['band'], $review['band']);
        $this->assertSame($screen['band_label'], $review['band_label']);
        $this->assertSame($screen['summary'], $review['summary']);
        $this->assertSame(FynnScoreEngine::MODEL_VERSION, $review['model_version']);

        // A summary, not the whole score. The dimensions, the curves and the
        // recommendations are what the FynnScore screen exists for.
        foreach (['dimensions', 'recommendations', 'bands', 'disclaimer'] as $absent) {
            $this->assertArrayNotHasKey($absent, $review);
        }
    }

    public function test_the_review_and_the_goals_screen_report_one_set_of_figures(): void
    {
        $user = $this->customer($this->healthy());
        $this->goalFor($user, ['title' => 'Sooner', 'target_date' => now()->addMonths(4)->toDateString()]);
        $this->goalFor($user, ['title' => 'Later', 'target_date' => now()->addMonths(30)->toDateString()]);

        $review = $this->review()['goals'];
        $screen = $this->getJson('/api/v1/goals')->assertOk()->json('data');

        $this->assertSame(2, $review['count']);
        $this->assertCount(2, $review['items']);

        // The Goals module's ordering, unchanged: nearest target date first,
        // and no goal singled out as the important one.
        $this->assertSame(['Sooner', 'Later'], array_column($review['items'], 'title'));
        $this->assertSame(['Sooner', 'Later'], array_column($screen, 'title'));

        foreach ($review['items'] as $i => $item) {
            $derived = $screen[$i]['derived'];

            $this->assertSame($derived['progress'], $item['progress']);
            $this->assertSame($derived['months_remaining'], $item['months_remaining']);
            $this->assertSame($derived['monthly_required'], $item['monthly_required']);
            $this->assertSame($derived['remaining_amount'], $item['remaining_amount']);
            $this->assertSame($derived['is_complete'], $item['is_complete']);
            $this->assertSame($derived['is_overdue'], $item['is_overdue']);
        }
    }

    /**
     * One ladder, however many vocabularies read it.
     *
     * Home translates the rung into its own keys and routes — deliberately,
     * because apps in the field read those keys — so the two surfaces agree
     * on the *decision* and not on the strings. An earlier version of this
     * asserted the routes were identical, and passed only because the
     * customer it used lands on `explore_options`, where the translation
     * happens to be the identity. A customer with an application in flight
     * gets `/applications/6` from the ladder and `/applications` from Home,
     * and the assertion would have been wrong about a product that was
     * right.
     *
     * So what is asserted is what the design actually promises: the rung
     * Home shows is the rung the ladder chose, across states where the
     * translation is not the identity.
     */
    #[DataProvider('ladderStates')]
    public function test_the_review_and_home_read_one_ladder(
        array $financials,
        bool $withApplication,
        string $expectedType,
        string $expectedHomeKey,
    ): void {
        $user = $this->customer($financials);

        if ($withApplication) {
            config(['fynnedge.lender.driver' => 'fixture']);
            app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');
            $this->postJson('/api/v1/applications', [
                'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
            ])->assertCreated();
        }

        $data = $this->review();
        $home = $this->getJson('/api/v1/home')->assertOk()->json('data.primary_action');

        // The plan begins with the next action: the same ladder, read twice.
        $this->assertSame($data['next_action']['type'], $data['plan'][0]['type']);
        $this->assertSame($data['next_action']['reason'], $data['plan'][0]['reason']);

        // And Home shows that rung, under its own name.
        $this->assertSame($expectedType, $data['next_action']['type']);
        $this->assertSame($expectedHomeKey, $home['key']);
    }

    /** @return iterable<string, array{array<string, int>, bool, string, string}> */
    public static function ladderStates(): iterable
    {
        // The identity case, where routes coincide and an equality
        // assertion proves nothing.
        yield 'nothing in flight' => [
            ['monthly_income' => 100000, 'monthly_expenses' => 40000, 'savings' => 600000],
            false, 'create_goal', 'set_goal',
        ];

        // The case that exposed the vacuity: same rung, different route.
        yield 'an application in flight' => [
            ['monthly_income' => 200000, 'monthly_expenses' => 40000, 'savings' => 900000],
            true, 'review_application', 'view_applications',
        ];

        yield 'nothing entered at all' => [
            [], false, 'complete_profile', 'complete_profile',
        ];
    }

    public function test_a_reached_goal_reads_the_same_everywhere(): void
    {
        $user = $this->customer($this->healthy());
        $this->goalFor($user, ['target_amount' => 90000, 'saved_amount' => 90000]);

        $item = $this->review()['goals']['items'][0];

        // Completion is derived, never a status. Module 34 settled that and
        // the review reads the same answer.
        $this->assertTrue($item['is_complete']);
        $this->assertSame(0, $item['monthly_required']);
        $this->assertSame('active', $user->goals()->first()->status);
    }

    public function test_the_goals_list_is_bounded_and_says_the_true_total(): void
    {
        $user = $this->customer($this->healthy());

        for ($i = 0; $i < 14; $i++) {
            $this->goalFor($user, [
                'title' => "Goal {$i}",
                'target_date' => now()->addMonths(6 + $i)->toDateString(),
            ]);
        }

        $goals = $this->review()['goals'];

        $this->assertSame(14, $goals['count']);
        $this->assertCount(FinancialIntelligence::MAX_GOALS, $goals['items']);
        $this->assertSame(10, FinancialIntelligence::MAX_GOALS);
    }

    // --- Asking for a review changes nothing -------------------------------------

    public function test_reading_the_review_writes_nothing(): void
    {
        $user = $this->customer($this->healthy());
        $this->goalFor($user);

        $snapshot = fn () => [
            'observations' => $user->financialObservations()->count(),
            'moments' => $user->financialMoments()->count(),
            'decisions' => $user->decisionContexts()->count(),
            'goals' => $user->goals()->count(),
        ];

        $before = $snapshot();

        // Asked for three times, the way a customer pulling to refresh does.
        $this->review();
        $this->review();
        $this->review();

        /*
         * A review is a read. Nothing schedules it, nothing records it, and
         * a table that grew when somebody opened a screen would be a record
         * of their attention rather than their money — the line Modules 31,
         * 32 and 35 each drew in turn.
         */
        $this->assertSame($before, $snapshot());
    }

    public function test_nothing_persists_or_schedules_a_review(): void
    {
        foreach (['financial_reviews', 'reviews', 'review_events', 'review_schedules'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }

        /*
         * And no endpoint of its own: the review is the intelligence read,
         * so a second request could describe the same customer at a second
         * instant and let two sections disagree.
         *
         * Matched on the resource rather than the word — Module 24's
         * extraction review lives at documents/{id}/scan/review and is a
         * different thing entirely.
         */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('financial-review', $route->uri());
            $this->assertStringNotContainsString('checkup', $route->uri());
            $this->assertNotSame('api/v1/review', $route->uri());
            $this->assertNotSame('api/v1/reviews', $route->uri());
        }
    }

    // --- What the review may not say ---------------------------------------------

    public function test_a_shortfall_is_never_answered_with_borrowing(): void
    {
        $user = $this->customer([
            'monthly_income' => 40000,
            'monthly_expenses' => 45000,
            'existing_emi' => 8000,
            'savings' => 0,
        ]);
        $this->goalFor($user);

        $data = $this->review();
        $whole = strtolower(json_encode($data));

        foreach ([
            'borrow', 'take a loan', 'credit card', 'top-up', 'refinance',
            'consolidat', 'explore your options', 'overdraft', 'apply now',
        ] as $sale) {
            $this->assertStringNotContainsString(
                $sale,
                $whole,
                "the review offered [{$sale}] to a customer spending more than they earn",
            );
        }

        // What it does show is their own figures, and the position plainly.
        $this->assertSame('negative', $data['position']['surplus_state']);
        $this->assertContains('review_profile', array_column($data['plan'], 'type'));
    }

    public function test_a_customer_with_no_income_is_shown_no_figures(): void
    {
        $this->customer(['monthly_expenses' => 30000, 'savings' => 100000]);

        $data = $this->review();

        $this->assertFalse($data['position']['is_measurable']);
        $this->assertFalse($data['score']['has_score']);
        $this->assertSame([], $data['goals']['items']);

        /*
         * The buffer survives, and should.
         *
         * How many months the recorded savings cover the recorded outgo is
         * a division of two figures the customer entered; it never needed
         * an income. Module 34 kept those two questions apart deliberately,
         * and suppressing a true answer because a different one is missing
         * would be the review knowing something and not saying it.
         */
        $this->assertNotNull($data['position']['emergency_statement']);
        $this->assertStringContainsString(
            'months of your monthly outgo',
            $data['position']['emergency_statement'],
        );

        // The engine's own reason for not having a score, rather than a
        // blank or a zero.
        $this->assertStringContainsString(
            'monthly income',
            strtolower($data['score']['summary']),
        );
        $this->assertContains('monthly_income', $data['score']['missing_requirements']);

        // And no rupee figure anywhere for somebody who entered none.
        $this->assertDoesNotMatchRegularExpression('/₹[0-9]/', json_encode($data));
    }

    public function test_a_buffer_with_nothing_to_cover_is_not_a_figure(): void
    {
        $this->customer([
            'monthly_income' => 80000,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'other_obligations' => 0,
            'savings' => 0,
        ]);

        $data = $this->review();

        $this->assertTrue($data['position']['is_measurable']);
        $this->assertNull($data['position']['emergency_months']);
        $this->assertNull($data['position']['emergency_statement']);
        $this->assertStringNotContainsString('less than a month', json_encode($data));
    }

    public function test_the_review_predicts_nothing_and_blames_nobody(): void
    {
        $user = $this->customer($this->healthy());
        $this->goalFor($user);
        $this->save(['savings' => 600000]);
        $this->save(['savings' => 900000]);

        $whole = strtolower(json_encode($this->review()));

        foreach ([
            'on track', 'you will reach', "you'll reach", 'guaranteed',
            'expected to', 'projected', 'financially secure',
            'because you', 'thanks to', 'as a result of',
            'your commitment', 'you must save',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $whole, "claimed: {$claim}");
        }
    }

    // --- Separation ---------------------------------------------------------------

    public function test_history_in_the_review_does_not_move_with_the_present(): void
    {
        $this->customer($this->healthy());

        $this->save(['savings' => 0]);
        $this->save(['savings' => 600000]);

        $before = $this->review()['history']['items'];
        $this->assertNotEmpty($before);

        // Everything about the customer's present changes.
        $this->save([
            'monthly_income' => 30000, 'monthly_expenses' => 28000,
            'existing_emi' => 12000, 'savings' => 0,
        ]);

        foreach ($before as $item) {
            $this->assertContains(
                $item,
                $this->review()['history']['items'],
                'a later edit rewrote what the review said about the past',
            );
        }
    }

    public function test_a_simulation_reaches_the_review_as_a_decision_and_nothing_else(): void
    {
        $user = $this->customer($this->healthy());
        $goal = $this->goalFor($user);
        $before = $this->review();

        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change', 'monthly_change' => -20000,
        ])->assertOk();

        $after = $this->review();

        /*
         * A customer trying a scenario has not changed their income, their
         * savings or their goal. The exploration is recorded in Module 32's
         * table, where it belongs, and the review's own figures are exactly
         * what they were.
         */
        $this->assertSame($before['position'], $after['position']);
        $this->assertSame($before['score'], $after['score']);
        $this->assertSame($before['goals'], $after['goals']);
        $this->assertSame($before['history'], $after['history']);
        $this->assertSame(0, $user->financialObservations()->count());

        $this->assertSame(
            $goal->only(['target_amount', 'saved_amount']),
            $goal->fresh()->only(['target_amount', 'saved_amount']),
        );

        $this->assertNotEmpty(
            $this->getJson('/api/v1/decisions')->assertOk()->json('data'),
        );
    }

    public function test_an_application_is_not_altered_by_being_reviewed(): void
    {
        config(['fynnedge.lender.driver' => 'fixture']);

        $user = $this->customer([
            'monthly_income' => 200000, 'monthly_expenses' => 40000, 'savings' => 900000,
        ]);
        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $before = $this->getJson("/api/v1/applications/{$id}")->assertOk()->json('data');
        $decisions = $user->decisionContexts()->count();

        $this->review();
        $this->review();

        $this->assertSame(
            $before,
            $this->getJson("/api/v1/applications/{$id}")->assertOk()->json('data'),
        );
        $this->assertSame($decisions, $user->decisionContexts()->count());
        $this->assertSame(0, $user->goals()->count());
    }

    public function test_a_blocked_application_leads_the_review(): void
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

        $data = $this->review();

        // The same rung Module 33 puts above everything else, reached
        // through the same ladder. The review does not decide this.
        $this->assertSame('resolve_submission_status', $data['next_action']['type']);

        // And nothing invites them to start another.
        $whole = strtolower(json_encode($data));
        foreach (['apply', 'submit again', 'try another', 'likely to be approved'] as $contradiction) {
            $this->assertStringNotContainsString($contradiction, $whole);
        }
    }

    public function test_a_goal_change_reaches_the_review_without_review_state(): void
    {
        $this->customer($this->healthy());

        $this->assertSame(0, $this->review()['goals']['count']);

        $this->postJson('/api/v1/goals', [
            'title' => 'Emergency fund', 'category' => 'personal',
            'target_amount' => 300000, 'saved_amount' => 120000,
            'target_date' => now()->addMonths(18)->toDateString(),
        ])->assertCreated();

        $after = $this->review();

        // Derived on read, so there is nothing to refresh and nothing that
        // can be stale.
        $this->assertSame(1, $after['goals']['count']);
        $this->assertSame('Emergency fund', $after['goals']['items'][0]['title']);
        $this->assertNotContains('create_goal', array_column($after['plan'], 'type'));
    }

    // --- Safety --------------------------------------------------------------------

    public function test_the_review_is_strictly_the_callers_own(): void
    {
        $other = $this->customer(['monthly_income' => 950000, 'monthly_expenses' => 40000]);
        $this->goalFor($other, ['title' => 'Somebody else\'s goal']);
        $this->assertSame(950000, $this->review()['position']['income']);

        $this->customer(['monthly_income' => 60000, 'monthly_expenses' => 30000]);

        $body = json_encode($this->review());

        $this->assertStringNotContainsString('950000', $body);
        $this->assertStringNotContainsString('Somebody else', $body);
        $this->assertSame(0, $this->review()['goals']['count']);
    }

    public function test_a_client_cannot_shape_its_own_review(): void
    {
        $user = $this->customer([
            'monthly_income' => 40000, 'monthly_expenses' => 45000, 'savings' => 0,
        ]);
        $this->goalFor($user);

        // Everything a client might try to assert about itself, as query
        // parameters on a read.
        $tampered = $this->getJson('/api/v1/intelligence?'.http_build_query([
            'customer_id' => $user->getKey() + 1,
            'user_id' => $user->getKey() + 1,
            'monthly_income' => 5000000,
            'surplus' => 5000000,
            'fynn_score' => 100,
            'next_action' => 'explore_options',
            'goal_progress' => 1,
        ]))->assertOk()->json('data');

        $this->assertSame($this->review(), $tampered);
        $this->assertSame('negative', $tampered['position']['surplus_state']);
        $this->assertNotSame(100, $tampered['score']['score']);
        $this->assertNotSame('explore_options', $tampered['next_action']['type']);
    }

    public function test_the_review_carries_nothing_sensitive(): void
    {
        $user = $this->customer($this->healthy());
        $this->goalFor($user);
        $this->save(['savings' => 600000]);

        $body = strtolower($this->getJson('/api/v1/intelligence')->assertOk()->getContent());

        foreach ([
            'aadhaar', 'pan_number', 'account_number', 'ifsc', 'storage_key',
            'vault', 'kyc', 'token', 'password', 'provider_payload',
            'idempotency', 'state_digest', 'follows_id', 'customer_id',
            'user_id', '"rule"', '"evidence"',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "leaked: {$leak}");
        }
    }

    public function test_the_review_costs_the_same_however_much_there_is_to_review(): void
    {
        $count = function (int $goals, int $saves): int {
            $user = $this->customer($this->healthy());

            for ($i = 0; $i < $goals; $i++) {
                $this->goalFor($user, [
                    'title' => "Goal {$i}",
                    'target_date' => now()->addMonths(6 + $i)->toDateString(),
                ]);
            }

            for ($i = 0; $i < $saves; $i++) {
                $this->save(['savings' => 200000 + ($i * 25000)]);
            }

            $queries = 0;
            \DB::listen(function () use (&$queries) {
                $queries++;
            });

            $this->review();

            return $queries;
        };

        /*
         * The review reads one state. The score, the goals, the position and
         * the ladder all come out of it, so gathering them costs nothing
         * beyond the read that was already happening.
         */
        $small = $count(1, 1);

        $this->assertGreaterThan(5, $small);
        $this->assertSame($small, $count(9, 6));
    }
}
