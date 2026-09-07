<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
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

    public function test_it_returns_the_profile_in_the_shape_the_app_parses(): void
    {
        $user = $this->customer();
        $user->customerProfile->update([
            'full_name' => 'Rahul Sharma',
            'age' => 34,
            'city' => 'Pune',
            'intent' => 'need_loan',
        ]);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id)
            ->assertJsonPath('data.mobile', $user->mobile)
            ->assertJsonPath('data.full_name', 'Rahul Sharma')
            ->assertJsonPath('data.age', 34)
            ->assertJsonPath('data.city', 'Pune')
            ->assertJsonPath('data.intent', 'need_loan')
            ->assertJsonStructure([
                'data' => [
                    'id', 'mobile', 'full_name', 'age', 'city', 'email',
                    'intent', 'primary_goal', 'onboarding_complete',
                ],
            ]);
    }

    public function test_a_profile_is_created_on_first_read(): void
    {
        // Deliberately not calling ensureProfiles: this checks the endpoint
        // copes with a customer who has no profile row yet.
        $user = User::factory()->create([
            'mobile' => config('fynnedge.demo_mobile'),
        ]);
        Sanctum::actingAs($user);
        $this->assertNull($user->customerProfile);

        $this->getJson('/api/v1/profile')->assertOk();

        $this->assertNotNull($user->fresh()->customerProfile);
    }

    public function test_it_updates_the_profile(): void
    {
        $this->customer();

        $this->putJson('/api/v1/profile', [
            'full_name' => 'Rahul S Sharma',
            'age' => 35,
            'city' => 'Mumbai',
            'intent' => 'compare_offers',
            'primary_goal' => 'home',
        ])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Rahul S Sharma')
            ->assertJsonPath('data.city', 'Mumbai')
            ->assertJsonPath('data.primary_goal', 'home');

        $this->assertDatabaseHas('customer_profiles', [
            'full_name' => 'Rahul S Sharma',
            'age' => 35,
        ]);
    }

    public function test_onboarding_completes_once_name_city_and_intent_are_known(): void
    {
        $user = $this->customer();
        $this->assertNull($user->customerProfile->onboarding_completed_at);

        $this->putJson('/api/v1/profile', [
            'full_name' => 'Rahul Sharma',
            'city' => 'Pune',
            'intent' => 'need_loan',
        ])->assertOk()->assertJsonPath('data.onboarding_complete', true);
    }

    public function test_it_rejects_an_under_age_customer(): void
    {
        $this->customer();

        $this->putJson('/api/v1/profile', ['age' => 15])
            ->assertStatus(422)
            ->assertJsonValidationErrors('age')
            ->assertJsonPath(
                'errors.age.0',
                'You must be at least 18 to use FynnEdge.',
            );
    }

    public function test_it_rejects_an_unknown_intent(): void
    {
        $this->customer();

        $this->putJson('/api/v1/profile', ['intent' => 'buy_a_yacht'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('intent');
    }

    public function test_it_rejects_a_malformed_email(): void
    {
        $this->customer();

        $this->putJson('/api/v1/profile', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }
}
