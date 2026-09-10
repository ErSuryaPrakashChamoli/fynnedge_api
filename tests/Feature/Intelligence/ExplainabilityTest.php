<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\FinancialMomentType;
use App\Domain\Intelligence\Insight;
use App\Domain\Intelligence\InsightRules;
use App\Domain\Intelligence\InsightSource;
use App\Domain\Intelligence\InsightType;
use App\Domain\Intelligence\NextActionRules;
use App\Domain\Intelligence\ScoreChangePolicy;
use App\Models\FinancialMoment;
use App\Models\User;
use App\Services\Intelligence\FinancialIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Why did this appear?
 *
 * Every statement FynnEdge makes about a customer's money has to be
 * answerable. Not "the model decided" and not a shrug — the rule that fired
 * and the values it fired on. That is what makes the intelligence layer
 * something a person can audit, and it is the difference between a product
 * that explains itself and one that asks to be believed.
 *
 * These tests are what make the requirement real rather than aspirational:
 * an insight or moment shipped without an explanation fails here.
 */
class ExplainabilityTest extends TestCase
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

    public function test_every_insight_can_say_which_rule_produced_it(): void
    {
        // A customer with something to say about: no income at all, then a
        // thin buffer once there is.
        foreach ([
            [],
            ['monthly_income' => 60000, 'monthly_expenses' => 50000,
                'existing_emi' => 5000, 'savings' => 1000],
        ] as $figures) {
            $user = $this->customer($figures);

            $state = app(FinancialIntelligence::class)->stateFor($user);
            $insights = app(InsightRules::class)->evaluate($state);

            $this->assertNotEmpty($insights, 'no insight to explain');

            foreach ($insights as $insight) {
                $explained = $insight->explain();

                $this->assertNotEmpty(
                    $explained['rule'],
                    "[{$insight->type->value}] appeared with no rule behind it",
                );

                // The rule is FynnEdge's, and the customer never sees it.
                $this->assertArrayNotHasKey('rule', $insight->toArray());
                $this->assertArrayNotHasKey('evidence', $insight->toArray());
            }
        }
    }

    public function test_every_next_action_can_say_why_it_was_chosen(): void
    {
        $user = $this->customer(['monthly_income' => 150000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000]);

        $action = app(NextActionRules::class)->evaluate(
            app(FinancialIntelligence::class)->stateFor($user)
        );

        $this->assertNotNull($action);
        $this->assertNotEmpty($action->explain()['rule']);

        // And the customer sees a reason in their own terms, not the rule.
        $this->assertArrayNotHasKey('rule', $action->toArray());
        $this->assertNotEmpty($action->toArray()['reason']);
    }

    public function test_every_moment_records_the_rule_that_created_it(): void
    {
        $user = $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 150000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000,
            'employment_type' => 'salaried',
        ])->assertOk();

        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated();

        $moments = FinancialMoment::where('user_id', $user->getKey())->get();

        $this->assertNotEmpty($moments);

        foreach ($moments as $moment) {
            $this->assertNotEmpty(
                $moment->rule,
                "[{$moment->type}] was recorded with no rule behind it",
            );
        }
    }

    /**
     * The documented provenance of the one rule Module 31 invented.
     *
     * Source, rule, input, output — the shape §71 asks for, asserted
     * rather than described in a comment.
     */
    public function test_the_score_change_rule_is_exactly_what_is_documented(): void
    {
        $this->assertSame(3, ScoreChangePolicy::POINTS);

        // Below the threshold in both directions: nothing is said.
        $this->assertFalse(ScoreChangePolicy::isMeaningful(0));
        $this->assertFalse(ScoreChangePolicy::isMeaningful(2));
        $this->assertFalse(ScoreChangePolicy::isMeaningful(-2));

        // At it and beyond: worth saying, in either direction.
        $this->assertTrue(ScoreChangePolicy::isMeaningful(3));
        $this->assertTrue(ScoreChangePolicy::isMeaningful(-3));
        $this->assertTrue(ScoreChangePolicy::isMeaningful(-96));
    }

    public function test_a_provider_derived_moment_is_marked_as_the_providers(): void
    {
        /*
         * Which moments are a provider's word rather than FynnEdge's,
         * stated on the type itself. A decision and a disbursal are things
         * only a provider can establish; everything else in the catalogue
         * is something FynnEdge or the customer did.
         */
        foreach (FinancialMomentType::cases() as $type) {
            $expected = in_array($type, [
                FinancialMomentType::ApplicationDecided,
                FinancialMomentType::ApplicationDisbursed,
            ], true);

            $this->assertSame(
                $expected,
                $type->comesFromProvider(),
                "[{$type->value}] disagrees about whose word it is",
            );
        }
    }

    public function test_no_insight_source_is_a_guess(): void
    {
        // Every source names an authoritative part of FynnEdge. There is
        // no `ai_guess`, no `model` and no `heuristic`.
        foreach (InsightSource::cases() as $source) {
            $this->assertNotContains($source->value, ['ai_guess', 'model', 'heuristic', 'ai']);
        }

        // And every insight type has a place in a deterministic order.
        $priorities = array_map(
            fn (InsightType $t) => $t->priority(),
            InsightType::cases(),
        );

        $this->assertSame(
            count($priorities),
            count(array_unique($priorities)),
            'two insight types share a priority, so their order is undefined',
        );
    }

    public function test_insights_arrive_in_priority_order_not_creation_order(): void
    {
        $user = $this->customer(['monthly_income' => 60000, 'monthly_expenses' => 50000,
            'existing_emi' => 5000, 'savings' => 1000]);

        $insights = app(InsightRules::class)->evaluate(
            app(FinancialIntelligence::class)->stateFor($user)
        );

        $priorities = array_map(fn (Insight $i) => $i->priority(), $insights);
        $sorted = $priorities;
        sort($sorted);

        $this->assertSame($sorted, $priorities);
    }
}
