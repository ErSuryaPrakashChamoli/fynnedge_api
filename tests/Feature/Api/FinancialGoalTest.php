<?php

namespace Tests\Feature\Api;

use App\Models\FinancialGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinancialGoalTest extends TestCase
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

    public function test_it_lists_only_the_customers_own_active_goals(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create(['title' => 'Mine']);
        FinancialGoal::factory()->for($user)->create([
            'title' => 'Archived',
            'status' => 'archived',
        ]);
        FinancialGoal::factory()->create(['title' => 'Someone else']);

        $response = $this->getJson('/api/v1/goals')->assertOk();

        $titles = collect($response->json('data'))->pluck('title');
        $this->assertEquals(['Mine'], $titles->all());
    }

    public function test_it_returns_derived_progress(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create([
            'title' => 'Emergency Fund',
            'target_amount' => 360000,
            'saved_amount' => 180000,
            'target_date' => now()->addDays(365),
        ]);

        $this->getJson('/api/v1/goals')
            ->assertOk()
            ->assertJsonPath('data.0.derived.progress', 0.5)
            ->assertJsonStructure([
                'data' => [
                    ['id', 'title', 'category', 'target_amount', 'target_date',
                        'saved_amount', 'status',
                        'derived' => ['progress', 'months_remaining', 'monthly_required'],
                    ],
                ],
            ]);
    }

    public function test_it_creates_a_goal(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/goals', [
            'title' => 'New Vehicle',
            'category' => 'car',
            'target_amount' => 800000,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => 50000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'New Vehicle')
            ->assertJsonPath('data.category', 'car');

        $this->assertDatabaseHas('financial_goals', [
            'user_id' => $user->id,
            'title' => 'New Vehicle',
        ]);
    }

    public function test_it_updates_a_goal(): void
    {
        $user = $this->customer();
        $goal = FinancialGoal::factory()->for($user)->create();

        $this->putJson("/api/v1/goals/{$goal->id}", [
            'title' => 'Renamed',
            'target_amount' => 900000,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed')
            ->assertJsonPath('data.target_amount', 900000);
    }

    public function test_it_deletes_a_goal(): void
    {
        $user = $this->customer();
        $goal = FinancialGoal::factory()->for($user)->create();

        $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNoContent();

        $this->assertDatabaseMissing('financial_goals', ['id' => $goal->id]);
    }

    public function test_a_customer_cannot_read_another_customers_goal(): void
    {
        $this->customer();
        $theirs = FinancialGoal::factory()->create();

        // 404, not 403: the API must not confirm the id exists.
        $this->getJson("/api/v1/goals/{$theirs->id}")->assertNotFound();
    }

    public function test_a_customer_cannot_update_another_customers_goal(): void
    {
        $this->customer();
        $theirs = FinancialGoal::factory()->create(['title' => 'Untouched']);

        $this->putJson("/api/v1/goals/{$theirs->id}", ['title' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('financial_goals', [
            'id' => $theirs->id,
            'title' => 'Untouched',
        ]);
    }

    public function test_a_customer_cannot_delete_another_customers_goal(): void
    {
        $this->customer();
        $theirs = FinancialGoal::factory()->create();

        $this->deleteJson("/api/v1/goals/{$theirs->id}")->assertNotFound();

        $this->assertDatabaseHas('financial_goals', ['id' => $theirs->id]);
    }

    public function test_it_rejects_a_target_date_in_the_past(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [
            'title' => 'Backwards',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->subYear()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_date');
    }

    public function test_it_rejects_saving_more_than_the_target(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [
            'title' => 'Overshot',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => 200000,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('saved_amount');
    }

    public function test_it_rejects_an_unknown_category(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [
            'title' => 'Mystery',
            'category' => 'spaceship',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_the_resource_contract_matches_what_the_app_parses(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create();

        $this->getJson('/api/v1/goals')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'title',
                        'category',
                        'target_amount',
                        'target_date',
                        'saved_amount',
                        'note',
                        'status',
                        'derived' => [
                            'progress',
                            'months_remaining',
                            'monthly_required',
                        ],
                    ],
                ],
            ]);
    }

    public function test_derived_figures_are_calculated_by_the_backend(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create([
            'target_amount' => 360000,
            'saved_amount' => 180000,
            'target_date' => now()->addDays(365),
        ]);

        $response = $this->getJson('/api/v1/goals')->assertOk();

        $this->assertSame(0.5, $response->json('data.0.derived.progress'));
        $this->assertGreaterThan(0, $response->json('data.0.derived.months_remaining'));
        $this->assertGreaterThan(0, $response->json('data.0.derived.monthly_required'));
    }

    public function test_an_overfunded_goal_needs_nothing_more_each_month(): void
    {
        $user = $this->customer();
        FinancialGoal::factory()->for($user)->create([
            'target_amount' => 100000,
            'saved_amount' => 100000,
            'target_date' => now()->addDays(365),
        ]);

        $this->getJson('/api/v1/goals')
            ->assertOk()
            ->assertJsonPath('data.0.derived.progress', 1)
            ->assertJsonPath('data.0.derived.monthly_required', 0);
    }

    public function test_ownership_comes_from_the_token_not_the_payload(): void
    {
        $victim = User::factory()->create(['mobile' => '9333333333']);
        $victim->ensureProfiles();

        $attacker = $this->customer();

        $this->postJson('/api/v1/goals', [
            'user_id' => $victim->id,
            'title' => 'Planted',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        // The goal belongs to the caller, whatever the payload claimed.
        $this->assertDatabaseHas('financial_goals', [
            'title' => 'Planted',
            'user_id' => $attacker->id,
        ]);
        $this->assertDatabaseMissing('financial_goals', [
            'title' => 'Planted',
            'user_id' => $victim->id,
        ]);
    }

    public function test_a_created_goal_persists_across_a_fresh_read(): void
    {
        $this->customer();

        $created = $this->postJson('/api/v1/goals', [
            'title' => 'New Vehicle',
            'category' => 'car',
            'target_amount' => 800000,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => 50000,
            'note' => 'Family car',
        ])->assertCreated()->json('data');

        // A separate request, not the create response.
        $this->getJson("/api/v1/goals/{$created['id']}")
            ->assertOk()
            ->assertJsonPath('data.title', 'New Vehicle')
            ->assertJsonPath('data.category', 'car')
            ->assertJsonPath('data.target_amount', 800000)
            ->assertJsonPath('data.saved_amount', 50000)
            ->assertJsonPath('data.note', 'Family car');
    }

    public function test_updating_the_same_values_twice_is_stable(): void
    {
        $user = $this->customer();
        $goal = FinancialGoal::factory()->for($user)->create();

        $payload = [
            'title' => 'Stable',
            'category' => 'home',
            'target_amount' => 500000,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => 100000,
        ];

        $first = $this->putJson("/api/v1/goals/{$goal->id}", $payload)
            ->assertOk()->json('data');
        $second = $this->putJson("/api/v1/goals/{$goal->id}", $payload)
            ->assertOk()->json('data');

        $this->assertSame($first, $second);
    }

    public function test_deleting_twice_reports_gone_rather_than_erroring(): void
    {
        $user = $this->customer();
        $goal = FinancialGoal::factory()->for($user)->create();

        $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNoContent();
        // The row is gone, so the second attempt is indistinguishable from
        // reaching for someone else's id: 404 either way.
        $this->deleteJson("/api/v1/goals/{$goal->id}")->assertNotFound();
    }

    public function test_cross_customer_access_never_reveals_existence(): void
    {
        $this->customer();
        $theirs = FinancialGoal::factory()->create();
        $missingId = $theirs->id + 10000;

        // A goal that exists but is not mine, and one that does not exist at
        // all, must be indistinguishable.
        $existing = $this->getJson("/api/v1/goals/{$theirs->id}");
        $absent = $this->getJson("/api/v1/goals/{$missingId}");

        $existing->assertNotFound();
        $absent->assertNotFound();

        // The customer-visible response must be identical. Comparing the
        // message rather than the whole body: debug traces differ by line
        // number and are absent in production anyway.
        $this->assertSame(
            $absent->json('message'),
            $existing->json('message'),
            'The 404 body reveals whether the goal exists',
        );
        $this->assertSame('That goal could not be found.', $existing->json('message'));
    }

    public function test_money_is_stored_as_decimal(): void
    {
        $this->customer();

        $created = $this->postJson('/api/v1/goals', [
            'title' => 'Precise',
            'category' => 'other',
            'target_amount' => 123456.78,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => 1000.25,
        ])->assertCreated()->json('data');

        $row = \DB::table('financial_goals')->where('id', $created['id'])->first();
        $this->assertSame('123456.78', (string) $row->target_amount);
        $this->assertSame('1000.25', (string) $row->saved_amount);
    }

    public function test_it_rejects_a_missing_title(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_it_rejects_a_malformed_target_date(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [
            'title' => 'Bad date',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => 'next tuesday-ish',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_date');
    }

    public function test_it_rejects_a_zero_or_negative_target(): void
    {
        $this->customer();

        foreach ([0, -500] as $amount) {
            $this->postJson('/api/v1/goals', [
                'title' => 'Nothing',
                'category' => 'other',
                'target_amount' => $amount,
                'target_date' => now()->addYear()->toDateString(),
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors('target_amount');
        }
    }

    public function test_it_rejects_a_negative_saved_amount(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [
            'title' => 'Negative',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
            'saved_amount' => -1,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('saved_amount');
    }

    public function test_validation_errors_use_the_keys_the_app_maps(): void
    {
        $this->customer();

        $this->postJson('/api/v1/goals', [])
            ->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => ['title', 'category', 'target_amount', 'target_date'],
            ]);
    }
}
