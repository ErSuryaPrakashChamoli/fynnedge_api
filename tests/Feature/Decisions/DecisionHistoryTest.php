<?php

namespace Tests\Feature\Decisions;

use App\Models\DecisionContext;
use App\Models\FinancialMoment;
use App\Models\FinancialObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the customer explored, and what FynnEdge refuses to say they did.
 *
 * A decision record says somebody ran a simulation or looked at two
 * products together. It never says they wanted, preferred, chose or
 * intended anything — those are the inferences a product makes when it
 * would rather have a story than a fact, and a customer shown one about
 * their own money has been told something untrue about themselves.
 */
class DecisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function simulate(array $overrides = [])
    {
        return $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
            ...$overrides,
        ]);
    }

    private function history(): array
    {
        return $this->getJson('/api/v1/decisions')->assertOk()->json('data');
    }

    // --- Nothing is reconstructed ---------------------------------------------

    public function test_a_customer_who_explored_nothing_has_no_history(): void
    {
        $this->customer();

        /*
         * The state every customer was in when this shipped. FynnEdge has a
         * financial profile, a score and a catalogue for them, and it
         * manufactures no decision from any of it.
         */
        $this->assertSame([], $this->history());
    }

    public function test_reading_the_history_does_not_create_history(): void
    {
        $user = $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/decisions')->assertOk();
            $this->getJson('/api/v1/home')->assertOk();
            $this->getJson('/api/v1/products/prod_bl_b')->assertOk();
            $this->getJson('/api/v1/fynn-match/prod_bl_b?amount=1000000&tenure_months=60')
                ->assertOk();
        }

        // Opening a product is not exploring it, and reading a history is
        // certainly not making one.
        $this->assertSame(0, DecisionContext::where('user_id', $user->getKey())->count());
        $this->assertSame([], $this->history());
    }

    // --- What is recorded, and what it says -----------------------------------

    public function test_running_a_simulation_records_a_simulation(): void
    {
        $this->customer();
        $this->simulate()->assertOk();

        $entry = $this->history()[0];

        $this->assertSame('simulation', $entry['type']);
        $this->assertTrue($entry['is_simulated']);
        $this->assertSame('prod_bl_b', $entry['product']['code']);

        // Nothing about wanting, choosing or intending.
        $whole = strtolower(json_encode($entry));
        foreach (['want', 'prefer', 'chose', 'intend', 'decided to'] as $inference) {
            $this->assertStringNotContainsString($inference, $whole);
        }
    }

    public function test_comparing_records_no_winner(): void
    {
        $this->customer();

        $this->postJson('/api/v1/compare', [
            'product_ids' => ['prod_bl_b', 'prod_bl_a'],
        ])->assertOk();

        $entry = $this->history()[0];

        $this->assertSame('comparison', $entry['type']);
        $this->assertStringContainsString('picked no winner', $entry['description']);

        /*
         * The affirmative forms only.
         *
         * "picked no winner" contains the word "winner" and is the opposite
         * of a claim — a sweep that flagged it would push the copy towards
         * saying nothing rather than towards saying something true.
         */
        $body = strtolower($this->getJson('/api/v1/decisions/'.$entry['id'])->getContent());
        foreach ([
            'the winner', 'is best', 'best option', 'we recommend',
            'recommended for you', 'cheapest', 'you should',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $body, "claimed: {$claim}");
        }
    }

    public function test_one_product_is_not_a_comparison(): void
    {
        $this->customer();

        $this->postJson('/api/v1/compare', ['product_ids' => ['prod_bl_b', 'prod_bl_a']])
            ->assertOk();

        // The endpoint requires two, and the recorder refuses fewer anyway.
        $this->postJson('/api/v1/compare', ['product_ids' => ['prod_bl_b']])
            ->assertStatus(422);

        $this->assertSame(1, DecisionContext::count());
    }

    public function test_an_application_is_read_rather_than_copied(): void
    {
        $this->customer();

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $entry = $this->history()[0];

        $this->assertSame('application_started', $entry['type']);
        $this->assertMatchesRegularExpression('/^FE-\d{4}-\d{6}$/', $entry['reference']);
        $this->assertFalse($entry['is_simulated']);

        /*
         * And no second record was written. `loan_applications` already
         * holds the terms shown; a copy could disagree with the original,
         * and the customer would be shown whichever the code happened to
         * read.
         */
        $this->assertSame(0, DecisionContext::count());
    }

    public function test_starting_an_application_is_not_submitting_one(): void
    {
        $this->customer();

        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated();

        $types = array_column($this->history(), 'type');

        // One entry, not two. An application that was started and left
        // alone was started and left alone.
        $this->assertSame(['application_started'], $types);
    }

    public function test_submitting_is_its_own_entry_at_its_own_time(): void
    {
        $this->customer();

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $this->travel(2)->hours();

        $this->postJson("/api/v1/applications/{$id}/submit", ['acknowledged' => true])
            ->assertOk();

        $history = $this->history();
        $types = array_column($history, 'type');

        $this->assertSame(['application_submitted', 'application_started'], $types);

        // Two decisions at two times, not one event described twice.
        $this->assertNotSame($history[0]['occurred_at'], $history[1]['occurred_at']);
    }

    public function test_submitting_does_not_mean_a_provider_received_it(): void
    {
        $this->customer();

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$id}/submit", ['acknowledged' => true])
            ->assertOk();

        $body = strtolower($this->getJson('/api/v1/decisions')->getContent());

        // Module 26's distinction, held here too. Nothing in a decision
        // history says a lender has anything.
        foreach ([
            'received by', 'lender received', 'provider received',
            'approved', 'under review', 'disbursed',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $body, "claimed: {$claim}");
        }
    }

    // --- Simulations stay simulations -------------------------------------------

    public function test_a_simulation_never_becomes_a_financial_fact(): void
    {
        $user = $this->customer();

        $this->simulate(['amount' => 5000000])->assertOk();

        // Not an observation, not a moment, not their profile.
        $this->assertSame(0, FinancialObservation::count());
        $this->assertSame(0, FinancialMoment::count());
        $this->assertSame('150000.00', $user->fresh()->financialProfile->monthly_income);

        // And it is labelled everywhere it is read.
        $entry = $this->history()[0];
        $this->assertTrue($entry['is_simulated']);

        $detail = $this->getJson('/api/v1/decisions/'.$entry['id'])->assertOk()->json('data');
        $this->assertTrue($detail['is_simulated']);
        $this->assertStringContainsString(
            'what-if',
            strtolower(json_encode($detail['explanation'])),
        );
    }

    // --- Deduplication -----------------------------------------------------------

    public function test_the_same_exploration_is_recorded_once(): void
    {
        $this->customer();

        // A customer dragging a slider fires the same simulation whenever
        // it lands back on the same figures.
        for ($i = 0; $i < 8; $i++) {
            $this->simulate()->assertOk();
        }

        $this->assertSame(1, DecisionContext::count());
    }

    public function test_a_different_exploration_is_its_own_record(): void
    {
        $this->customer();

        $this->simulate(['amount' => 1000000])->assertOk();
        $this->simulate(['amount' => 1500000])->assertOk();
        $this->simulate(['amount' => 1500000, 'tenure_months' => 48])->assertOk();

        $this->assertSame(3, DecisionContext::count());
    }

    public function test_changed_circumstances_make_a_new_exploration(): void
    {
        $this->customer();
        $this->simulate()->assertOk();

        // The same loan, against a different financial picture, is a
        // different thing to have explored — the ratio beside it differs.
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 90000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000,
            'employment_type' => 'salaried',
        ])->assertOk();

        $this->simulate()->assertOk();

        $this->assertSame(2, DecisionContext::count());
    }
}
