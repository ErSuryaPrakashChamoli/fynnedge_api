<?php

namespace Tests\Feature\Api;

use App\Models\FinancialGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FynnScoreTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update(array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ], $financials));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/fynn-score')->assertStatus(401);
    }

    public function test_it_returns_a_real_score_for_the_caller(): void
    {
        $this->customer();

        // emi_ratio 20% -> 100, expense_ratio 40% -> 100, 3 of 6 months -> 50
        // (100 + 100 + 50) / 3 = 83.33 -> 83
        $this->getJson('/api/v1/fynn-score')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.score', 83)
            ->assertJsonPath('data.band', 'strong')
            ->assertJsonPath('data.band_label', 'Strong')
            ->assertJsonPath('data.model_version', 'fynnscore-v1');
    }

    public function test_the_response_carries_everything_the_ui_needs(): void
    {
        $this->customer();

        $this->getJson('/api/v1/fynn-score')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'status', 'model_version', 'scale' => ['min', 'max'],
                    'score', 'band', 'band_label', 'summary',
                    'dimensions' => [[
                        'key', 'name', 'score', 'weight', 'metric',
                        'metric_value', 'metric_unit', 'full_marks_at',
                        'zero_at', 'explanation', 'is_helping',
                    ]],
                    'helping', 'needs_attention',
                    'recommendations' => [[
                        'dimension', 'title', 'detail', 'metric',
                        'current_value', 'target_value', 'current_score',
                        'projected_score', 'delta',
                    ]],
                    'metrics', 'missing_requirements', 'inputs_updated_at',
                    'disclaimer', 'bands',
                ],
            ]);
    }

    public function test_it_returns_exactly_the_three_v1_dimensions(): void
    {
        $this->customer();

        $keys = collect($this->getJson('/api/v1/fynn-score')->json('data.dimensions'))
            ->pluck('key')->all();

        $this->assertSame(
            ['emi_burden', 'spending_control', 'emergency_buffer'],
            $keys,
        );
    }

    public function test_zero_income_yields_insufficient_data(): void
    {
        $this->customer(['monthly_income' => 0]);

        $this->getJson('/api/v1/fynn-score')
            ->assertOk()
            ->assertJsonPath('data.status', 'insufficient_data')
            ->assertJsonPath('data.score', null)
            ->assertJsonPath('data.band', null)
            ->assertJsonPath('data.missing_requirements', ['monthly_income'])
            ->assertJsonPath('data.dimensions', []);
    }

    public function test_goals_do_not_change_the_score(): void
    {
        $user = $this->customer();

        $before = $this->getJson('/api/v1/fynn-score')->json('data.score');

        FinancialGoal::factory()->for($user)->create([
            'target_amount' => 5000000,
            'saved_amount' => 100,
            'target_date' => now()->addDays(10),
        ]);

        $this->assertSame(
            $before,
            $this->getJson('/api/v1/fynn-score')->json('data.score'),
            'A goal must never move the numerical FynnScore',
        );
    }

    public function test_a_financial_profile_change_moves_the_score(): void
    {
        $this->customer();
        $before = $this->getJson('/api/v1/fynn-score')->json('data.score');

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 45000,
            'savings' => 180000,
            'employment_type' => 'salaried',
        ])->assertOk();

        $after = $this->getJson('/api/v1/fynn-score')->json('data.score');

        $this->assertNotSame($before, $after);
        $this->assertLessThan($before, $after);
    }

    public function test_it_never_returns_another_customers_score(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        $other->financialProfile->update([
            'monthly_income' => 1000000,
            'monthly_expenses' => 10000,
            'existing_emi' => 0,
            'savings' => 9000000,
        ]);

        // A weak profile for the caller.
        $this->customer([
            'monthly_income' => 50000,
            'monthly_expenses' => 45000,
            'existing_emi' => 30000,
            'savings' => 0,
        ]);

        $this->getJson('/api/v1/fynn-score')
            ->assertOk()
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.metrics.monthly_income', 50000);
    }

    public function test_home_serves_the_same_score(): void
    {
        $this->customer();

        $direct = $this->getJson('/api/v1/fynn-score')->json('data');
        $home = $this->getJson('/api/v1/home')->json('data.score');

        // Home must not compute its own version.
        $this->assertSame($direct['score'], $home['score']);
        $this->assertSame($direct['model_version'], $home['model_version']);
        $this->assertSame($direct['dimensions'], $home['dimensions']);
    }

    public function test_home_reflects_a_profile_change_immediately(): void
    {
        $this->customer();
        $before = $this->getJson('/api/v1/home')->json('data.score.score');

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 600000,
            'employment_type' => 'salaried',
        ])->assertOk();

        $this->assertGreaterThan(
            $before,
            $this->getJson('/api/v1/home')->json('data.score.score'),
        );
    }

    public function test_no_prototype_values_appear_anywhere(): void
    {
        $this->customer();
        $body = $this->getJson('/api/v1/fynn-score')->getContent();

        // The placeholder dimensions needed data FynnEdge does not hold.
        $this->assertStringNotContainsString('Income stability', $body);
        $this->assertStringNotContainsString('Credit conduct', $body);
        $this->assertStringNotContainsString('No missed repayments', $body);
    }

    public function test_recommendation_deltas_are_reproducible_from_the_response(): void
    {
        $this->customer();
        $data = $this->getJson('/api/v1/fynn-score')->json('data');

        foreach ($data['recommendations'] as $r) {
            $this->assertSame($data['score'], $r['current_score']);
            $this->assertSame(
                $r['projected_score'] - $r['current_score'],
                $r['delta'],
            );
        }
    }
}
