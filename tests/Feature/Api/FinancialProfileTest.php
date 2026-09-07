<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialProfileTest extends TestCase
{
    use RefreshDatabase;

    /** Creates a customer and authenticates as them for the request. */
    private function customer(): User
    {
        $user = User::factory()->create([
            'mobile' => config('fynnedge.demo_mobile'),
        ]);
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_returns_raw_and_derived_figures(): void
    {
        $user = $this->customer();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
            'employment_type' => 'business',
        ]);

        $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->assertJsonPath('data.monthly_income', 100000)
            ->assertJsonPath('data.employment_type', 'business')
            // Derived values are the backend's definition, not the app's guess.
            ->assertJsonPath('data.derived.surplus', 40000)
            ->assertJsonPath('data.derived.emi_ratio', 20)
            ->assertJsonPath('data.derived.expense_ratio', 40)
            ->assertJsonPath('data.derived.monthly_outgo', 60000)
            ->assertJsonPath('data.derived.emergency_months', 3);
    }

    public function test_other_obligations_reduce_the_surplus(): void
    {
        $user = $this->customer();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 10000,
            'savings' => 180000,
        ]);

        $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->assertJsonPath('data.derived.surplus', 30000)
            ->assertJsonPath('data.derived.monthly_outgo', 70000);
    }

    public function test_it_updates_the_financial_profile(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 150000,
            'monthly_expenses' => 50000,
            'existing_emi' => 25000,
            'savings' => 400000,
            'other_obligations' => 5000,
            'employment_type' => 'salaried',
        ])
            ->assertOk()
            ->assertJsonPath('data.monthly_income', 150000)
            ->assertJsonPath('data.derived.surplus', 70000);

        $this->assertDatabaseHas('financial_profiles', [
            'monthly_income' => 150000.00,
            'employment_type' => 'salaried',
        ]);
    }

    public function test_it_requires_the_core_figures(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'monthly_income',
                'monthly_expenses',
                'existing_emi',
                'savings',
                'employment_type',
            ]);
    }

    public function test_it_rejects_negative_money(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => -1,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_income');
    }

    public function test_it_rejects_an_unknown_employment_type(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'astronaut',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('employment_type');
    }

    public function test_it_flags_outgoings_far_above_income_as_a_typo(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 50000,
            'monthly_expenses' => 400000,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_expenses');
    }

    public function test_zero_income_does_not_divide_by_zero(): void
    {
        $this->customer();

        $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->assertJsonPath('data.derived.emi_ratio', 0)
            ->assertJsonPath('data.derived.emergency_months', 0);
    }

    public function test_patch_behaves_like_put(): void
    {
        $this->customer();

        $this->patchJson('/api/v1/financial-profile', [
            'monthly_income' => 120000,
            'monthly_expenses' => 45000,
            'existing_emi' => 15000,
            'savings' => 200000,
            'employment_type' => 'professional',
        ])
            ->assertOk()
            ->assertJsonPath('data.monthly_income', 120000)
            ->assertJsonPath('data.derived.surplus', 60000);
    }

    public function test_ownership_comes_from_the_token_not_the_payload(): void
    {
        $victim = User::factory()->create(['mobile' => '9111111111']);
        $victim->ensureProfiles();
        $victim->financialProfile->update(['monthly_income' => 999999]);

        $attacker = $this->customer();

        // A client-supplied user_id must be ignored outright.
        $this->putJson('/api/v1/financial-profile', [
            'user_id' => $victim->id,
            'id' => $victim->financialProfile->id,
            'monthly_income' => 1,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])->assertOk();

        // The attacker's own profile changed...
        $this->assertSame(
            '1.00',
            $attacker->financialProfile->fresh()->monthly_income,
        );

        // ...and the victim's did not.
        $this->assertSame(
            '999999.00',
            $victim->financialProfile->fresh()->monthly_income,
        );
    }

    public function test_one_customer_never_reads_anothers_figures(): void
    {
        $other = User::factory()->create(['mobile' => '9222222222']);
        $other->ensureProfiles();
        $other->financialProfile->update(['monthly_income' => 777777]);

        $mine = $this->customer();
        $mine->financialProfile->update(['monthly_income' => 111111]);

        $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->assertJsonPath('data.monthly_income', 111111);
    }

    public function test_money_is_stored_as_decimal_not_float(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 100000.55,
            'monthly_expenses' => 40000.45,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])->assertOk();

        // Exact string comparison: a float column would drift here.
        $row = \DB::table('financial_profiles')->first();
        $this->assertSame('100000.55', (string) $row->monthly_income);
        $this->assertSame('40000.45', (string) $row->monthly_expenses);
    }

    public function test_an_update_persists_across_a_fresh_read(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 250000,
            'monthly_expenses' => 60000,
            'existing_emi' => 30000,
            'savings' => 900000,
            'other_obligations' => 10000,
            'employment_type' => 'professional',
        ])->assertOk();

        // A separate request, not the update response.
        $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->assertJsonPath('data.monthly_income', 250000)
            ->assertJsonPath('data.other_obligations', 10000)
            ->assertJsonPath('data.employment_type', 'professional')
            ->assertJsonPath('data.derived.surplus', 150000)
            ->assertJsonPath('data.derived.monthly_outgo', 100000);
    }

    public function test_saving_the_same_values_twice_is_stable(): void
    {
        $this->customer();

        $payload = [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
            'other_obligations' => 0,
            'employment_type' => 'business',
        ];

        $first = $this->putJson('/api/v1/financial-profile', $payload)
            ->assertOk()
            ->json('data');

        $second = $this->putJson('/api/v1/financial-profile', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame($first, $second);
    }

    public function test_it_rejects_a_non_numeric_figure(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 'quite a lot',
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_income');
    }

    public function test_it_rejects_an_absurdly_large_figure(): void
    {
        $this->customer();

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 999999999999,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'savings' => 0,
            'employment_type' => 'salaried',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('monthly_income');
    }

    public function test_validation_errors_are_keyed_by_field(): void
    {
        $this->customer();

        // The app maps these keys onto its inputs, so the shape matters.
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => -1,
            'existing_emi' => -5,
            'employment_type' => 'astronaut',
        ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'monthly_income',
                    'monthly_expenses',
                    'existing_emi',
                    'savings',
                    'employment_type',
                ],
            ]);
    }
}
