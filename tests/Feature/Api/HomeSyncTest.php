<?php

namespace Tests\Feature\Api;

use App\Models\FinancialGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Home and the Financial Profile screen must never disagree. Both read the
 * same rows, so a saved change is visible on the next Home load.
 */
class HomeSyncTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_home_reads_the_authenticated_customers_own_figures(): void
    {
        $user = $this->customer();
        $user->customerProfile->update(['full_name' => 'Rahul']);
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.customer.first_name', 'Rahul')
            ->assertJsonPath('data.financial.monthly_income', 100000)
            ->assertJsonPath('data.financial.surplus', 40000);
    }

    public function test_a_financial_profile_save_is_visible_on_home(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 175000,
            'monthly_expenses' => 45000,
            'existing_emi' => 15000,
            'savings' => 500000,
            'other_obligations' => 5000,
            'employment_type' => 'salaried',
        ])->assertOk();

        // The exact situation the module forbids: Home showing a stale figure.
        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.financial.monthly_income', 175000)
            ->assertJsonPath('data.financial.other_obligations', 5000)
            ->assertJsonPath('data.financial.surplus', 110000);
    }

    public function test_home_shows_only_the_customers_own_goals(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create(['title' => 'Mine']);
        FinancialGoal::factory()->create(['title' => 'Someone else']);

        $response = $this->getJson('/api/v1/home')->assertOk();

        $titles = collect($response->json('data.goals.items'))->pluck('title');
        $this->assertEquals(['Mine'], $titles->all());
    }

    public function test_home_never_leaks_another_customers_figures(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        $other->financialProfile->update(['monthly_income' => 999999]);

        $mine = $this->customer();
        $mine->financialProfile->update(['monthly_income' => 100000]);

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonPath('data.financial.monthly_income', 100000);
    }
}
