<?php

namespace Tests\Feature\Api;

use App\Models\FinancialGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/financial-summary')->assertStatus(401);
    }

    public function test_it_returns_the_engine_result_for_the_caller(): void
    {
        $this->customer();

        $this->getJson('/api/v1/financial-summary')
            ->assertOk()
            ->assertJsonPath('data.inputs.monthly_income', 100000)
            ->assertJsonPath('data.derived.surplus', 40000)
            ->assertJsonPath('data.derived.monthly_outgo', 60000)
            ->assertJsonPath('data.derived.emi_ratio', 20)
            ->assertJsonPath('data.derived.emergency_months', 3)
            ->assertJsonPath('data.derived.has_income', true);
    }

    public function test_it_includes_aggregate_goal_figures(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create([
            'target_amount' => 360000,
            'saved_amount' => 180000,
            'target_date' => now()->addDays(365),
        ]);
        FinancialGoal::factory()->for($user)->create([
            'target_amount' => 1500000,
            'saved_amount' => 320000,
            'target_date' => now()->addDays(540),
        ]);

        $this->getJson('/api/v1/financial-summary')
            ->assertOk()
            ->assertJsonPath('data.goals.count', 2)
            ->assertJsonPath('data.goals.total_target', 1860000)
            ->assertJsonPath('data.goals.total_saved', 500000)
            ->assertJsonPath('data.goals.total_remaining', 1360000)
            ->assertJsonCount(2, 'data.goals.items');
    }

    public function test_it_ships_the_formulas_alongside_the_numbers(): void
    {
        $this->customer();

        // So a future feature can explain a figure without rebuilding it.
        $this->getJson('/api/v1/financial-summary')
            ->assertOk()
            ->assertJsonPath('data.definitions.surplus', 'monthly_income - monthly_outgo (may be negative)')
            ->assertJsonStructure([
                'data' => [
                    'definitions' => [
                        'monthly_outgo', 'surplus', 'emi_ratio',
                        'emergency_months', 'goal_progress',
                        'goal_monthly_required', 'rounding',
                    ],
                ],
            ]);
    }

    public function test_it_never_reads_another_customers_data(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        $other->financialProfile->update(['monthly_income' => 999999]);
        FinancialGoal::factory()->for($other)->create();

        $this->customer();

        $this->getJson('/api/v1/financial-summary')
            ->assertOk()
            ->assertJsonPath('data.inputs.monthly_income', 100000)
            ->assertJsonPath('data.goals.count', 0);
    }

    public function test_a_customer_with_no_data_still_gets_a_valid_summary(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/financial-summary')
            ->assertOk()
            ->assertJsonPath('data.derived.surplus', 0)
            ->assertJsonPath('data.derived.emi_ratio', 0)
            ->assertJsonPath('data.derived.has_income', false)
            ->assertJsonPath('data.goals.count', 0);
    }
}
