<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\FinancialMomentType;
use App\Domain\Intelligence\ScoreChangePolicy;
use App\Models\FinancialMoment;
use App\Models\FinancialObservation;
use App\Models\User;
use App\Services\Intelligence\FinancialIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What FynnEdge refuses to claim.
 *
 * This is the file the module exists for. FynnEdge kept no financial
 * history before Module 31 — `financial_profiles` is one mutable row, so an
 * income edit destroyed the previous income, and no FynnScore had ever been
 * remembered. Every trend, every "your spending is improving", every "your
 * score went up by six" was therefore unavailable, and the failure mode
 * would be to produce one anyway from a single reading.
 *
 * A fabricated trend is worse than no trend, because a customer believes it
 * and acts on it. These tests are what stop that.
 */
class NoInventedIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);

        if ($financials !== []) {
            $user->financialProfile->update($financials);
        }

        Sanctum::actingAs($user);

        return $user;
    }

    private function healthy(): array
    {
        return [
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ];
    }

    private function updateProfile(array $figures): void
    {
        $this->putJson('/api/v1/financial-profile', [
            ...$figures,
            'employment_type' => 'salaried',
        ])->assertOk();
    }

    private function intelligence(): array
    {
        return $this->getJson('/api/v1/intelligence')->assertOk()->json('data');
    }

    /** @return list<string> */
    private function insightTypes(): array
    {
        return array_column($this->intelligence()['insights'], 'type');
    }

    // --- No history means no trend ------------------------------------------

    public function test_a_customer_with_one_reading_has_no_history_at_all(): void
    {
        $this->customer($this->healthy());

        $data = $this->intelligence();

        /*
         * The state every customer was in when Module 31 shipped. FynnEdge
         * has never observed them, so it can compare nothing with nothing,
         * and it says so rather than showing a blank where a trend would go.
         */
        $this->assertSame(0, $data['history']['observations']);
        $this->assertFalse($data['history']['has_comparable_history']);
    }

    public function test_a_single_observation_is_still_not_a_history(): void
    {
        $user = $this->customer($this->healthy());

        // One change: one observation. Two points are needed to draw a line
        // and one is not two.
        $this->updateProfile(['monthly_income' => 160000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000]);

        $this->assertSame(1, FinancialObservation::where('user_id', $user->getKey())->count());
        $this->assertFalse($this->intelligence()['history']['has_comparable_history']);
    }

    public function test_no_income_or_expense_trend_is_ever_claimed(): void
    {
        $this->customer($this->healthy());

        // Income and expenses both change, several times.
        $this->updateProfile(['monthly_income' => 160000, 'monthly_expenses' => 38000,
            'existing_emi' => 10000, 'savings' => 600000]);
        $this->updateProfile(['monthly_income' => 175000, 'monthly_expenses' => 35000,
            'existing_emi' => 10000, 'savings' => 650000]);

        $data = $this->intelligence();

        /*
         * Module 35 changed exactly one thing about this, and it is worth
         * being precise about which.
         *
         * FynnEdge may now say "recorded monthly income changed from
         * ₹1,60,000 to ₹1,75,000", because both figures are rows it wrote
         * down. What it still may not do is narrate a direction as a story
         * — rising, falling, improving, a trend — because two readings
         * taken whenever a customer happened to edit a form are not a
         * series, and no amount of stored history makes them one.
         *
         * So the history block is examined on its own terms below, and
         * everything else in the product must still be silent.
         */
        $history = $data['history'];
        unset($data['history']);

        $body = strtolower(json_encode($data).json_encode(
            $this->getJson('/api/v1/intelligence/timeline')->json('data')
        ));

        foreach ([
            'increased', 'decreased', 'rising', 'falling', 'trend',
            'growing', 'improving spending', '%',
        ] as $claim) {
            $this->assertStringNotContainsString(
                $claim,
                $body,
                "claimed a trend outside the observed history: {$claim}",
            );
        }

        // Narrative direction is refused everywhere, history included.
        foreach (['rising', 'falling', 'trend', 'growing', 'improving'] as $story) {
            $this->assertStringNotContainsString(
                $story,
                strtolower(json_encode($history)),
                "narrated a trend: {$story}",
            );
        }

        /*
         * And every direction word that does appear names two figures
         * FynnEdge actually stored, at two moments it actually recorded.
         */
        $observed = FinancialObservation::orderBy('id')->get();
        $this->assertCount(2, $observed);

        foreach ($history['items'] as $item) {
            $this->assertContains($item['direction'], ['increased', 'decreased']);
            $this->assertNotSame($item['previous_value'], $item['current_value']);
            $this->assertNotNull($item['previous_observed_at']);

            $this->assertTrue(
                $observed->contains(
                    fn (FinancialObservation $o) => (float) $o->{$item['metric'] === 'fynn_score' ? 'score' : $item['metric']}
                        === (float) $item['current_value']
                ),
                "[{$item['metric']}] was not one of the figures FynnEdge stored",
            );
        }
    }

    public function test_a_score_cannot_change_against_a_score_nobody_observed(): void
    {
        $user = $this->customer($this->healthy());

        /*
         * A score exists and there is no earlier one, so no change can be
         * stated however different today's figures are from a guess.
         *
         * Asserted on moments rather than insights because a score change
         * is not an insight: it is something that happened, so it belongs
         * in the timeline. Deriving it on every read would mean deriving it
         * against whatever the latest observation happened to be.
         */
        $this->assertSame(
            [],
            FinancialMoment::where('user_id', $user->getKey())
                ->whereIn('type', [
                    FinancialMomentType::ScoreImproved->value,
                    FinancialMomentType::ScoreDeclined->value,
                ])->pluck('type')->all(),
        );

        $this->assertFalse($this->intelligence()['history']['has_comparable_history']);
    }

    public function test_a_score_change_is_never_offered_as_a_current_insight(): void
    {
        $this->customer();
        $this->updateProfile($this->healthy());
        $this->updateProfile(['monthly_income' => 60000, 'monthly_expenses' => 50000,
            'existing_emi' => 25000, 'savings' => 0]);

        // Insights describe how things are; a score having moved is
        // something that happened. Keeping them apart is what stops the
        // same fact being recomputed against the wrong baseline on a read.
        $this->assertNotContains('score_change', $this->insightTypes());
    }

    public function test_the_first_score_is_an_arrival_and_not_an_improvement(): void
    {
        $user = $this->customer();

        // Nothing entered yet: no score, and nothing to say about one.
        $this->assertSame([], FinancialMoment::where('user_id', $user->getKey())->pluck('type')->all());

        $this->updateProfile($this->healthy());

        $types = FinancialMoment::where('user_id', $user->getKey())->pluck('type')->all();

        // "Your FynnScore is ready" is true. "Your FynnScore improved"
        // would not be — it improved from nothing, which is not a number.
        $this->assertContains(FinancialMomentType::ScoreAvailable->value, $types);
        $this->assertNotContains(FinancialMomentType::ScoreImproved->value, $types);
    }

    public function test_a_score_becomes_available_once_however_often_the_profile_changes(): void
    {
        $user = $this->customer();

        $this->updateProfile($this->healthy());
        $this->updateProfile(['monthly_income' => 151000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000]);
        $this->updateProfile(['monthly_income' => 152000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000]);

        $this->assertSame(
            1,
            FinancialMoment::where('user_id', $user->getKey())
                ->where('type', FinancialMomentType::ScoreAvailable->value)
                ->count(),
        );
    }

    // --- Rounding noise is not news -----------------------------------------

    public function test_a_trivial_edit_does_not_become_a_score_moment(): void
    {
        $user = $this->customer();
        $this->updateProfile($this->healthy());

        // A few hundred rupees of savings. The score barely moves, and
        // mentioning it would train the customer to ignore the ones that
        // matter.
        $this->updateProfile(['monthly_income' => 150000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 601000]);

        $moments = FinancialMoment::where('user_id', $user->getKey())->pluck('type')->all();

        $this->assertNotContains(FinancialMomentType::ScoreImproved->value, $moments);
        $this->assertNotContains(FinancialMomentType::ScoreDeclined->value, $moments);
    }

    public function test_a_real_move_does_become_one_and_records_both_figures(): void
    {
        $user = $this->customer();
        $this->updateProfile($this->healthy());

        $before = app(FinancialIntelligence::class)->stateFor($user->fresh())->score->score;

        // Materially different: stretched rather than comfortable.
        $this->updateProfile(['monthly_income' => 60000, 'monthly_expenses' => 50000,
            'existing_emi' => 25000, 'savings' => 0]);

        $moment = FinancialMoment::where('user_id', $user->getKey())
            ->where('type', FinancialMomentType::ScoreDeclined->value)
            ->first();

        $this->assertNotNull($moment, 'a real score move produced no moment');

        $after = app(FinancialIntelligence::class)->stateFor($user->fresh())->score->score;

        // The figures as they were, written at the time — which is why the
        // sentence is stored rather than derived on read.
        $this->assertStringContainsString((string) $before, $moment->description);
        $this->assertStringContainsString((string) $after, $moment->description);
        $this->assertGreaterThanOrEqual(ScoreChangePolicy::POINTS, $before - $after);
    }

    // --- History is history --------------------------------------------------

    public function test_a_historical_moment_is_not_rewritten_by_what_happens_later(): void
    {
        $user = $this->customer();
        $this->updateProfile(['monthly_income' => 60000, 'monthly_expenses' => 50000,
            'existing_emi' => 25000, 'savings' => 0]);

        // Up, then down again.
        $this->updateProfile($this->healthy());

        $improved = FinancialMoment::where('user_id', $user->getKey())
            ->where('type', FinancialMomentType::ScoreImproved->value)
            ->first();

        $this->assertNotNull($improved);
        $sentence = $improved->description;

        $this->updateProfile(['monthly_income' => 55000, 'monthly_expenses' => 52000,
            'existing_emi' => 26000, 'savings' => 0]);

        /*
         * The improvement happened. It goes on having happened after the
         * score falls again, and the sentence describing it still names the
         * figures it named — because a moment records what occurred, not
         * what is currently the case.
         */
        $this->assertSame($sentence, $improved->fresh()->description);

        // And the fall is its own moment rather than an edit to that one.
        $this->assertGreaterThanOrEqual(
            1,
            FinancialMoment::where('user_id', $user->getKey())
                ->where('type', FinancialMomentType::ScoreDeclined->value)
                ->count(),
        );
    }

    public function test_nothing_regenerates_history_from_todays_figures(): void
    {
        $user = $this->customer($this->healthy());

        // A customer who has never changed anything has no story, however
        // many times the timeline is read.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/intelligence/timeline')->assertOk();
            $this->getJson('/api/v1/intelligence')->assertOk();
        }

        $this->assertSame(0, FinancialMoment::where('user_id', $user->getKey())->count());
        $this->assertSame(0, FinancialObservation::where('user_id', $user->getKey())->count());
    }

    // --- A simulation is not history ------------------------------------------

    public function test_a_fynntwin_simulation_never_becomes_a_financial_fact(): void
    {
        $user = $this->customer($this->healthy());

        // A large change, simulated. Not committed to anything.
        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'saving_change',
            'monthly_amount' => 50000,
            'months' => 24,
        ])->assertOk();

        $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'expense_change',
            'monthly_change' => -20000,
        ])->assertOk();

        /*
         * A simulation is a question the customer asked, not something that
         * happened to them. Nothing about it is remembered as fact, and the
         * figures they tried out do not become their figures.
         */
        $this->assertSame(0, FinancialMoment::where('user_id', $user->getKey())->count());
        $this->assertSame(0, FinancialObservation::where('user_id', $user->getKey())->count());
        $this->assertSame(
            '150000.00',
            $user->fresh()->financialProfile->monthly_income,
        );
    }
}
