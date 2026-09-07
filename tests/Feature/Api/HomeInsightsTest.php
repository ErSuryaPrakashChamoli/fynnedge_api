<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Home insights are still placeholder content until the Smart Insight engine
 * exists. Until then they must not assert anything about the customer's
 * money, because Home now shows that customer's real figures right beside
 * them and any seeded number would contradict it.
 */
class HomeInsightsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update(array_merge([
            'monthly_income' => 250000,
            'monthly_expenses' => 60000,
            'existing_emi' => 5000,
            'savings' => 2000000,
        ], $financials));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_home_carries_no_seeded_insight_block(): void
    {
        $this->customer();

        $data = $this->getJson('/api/v1/home')->assertOk()->json('data');

        // Module 7 neutralised the seeded Smart Insight copy. Module 15
        // removed the block outright: what Home says about a customer now
        // comes from the attention rules, each traceable to an engine
        // figure, so there is nothing left to neutralise.
        $this->assertArrayNotHasKey('insights', $data);
    }

    public function test_home_carries_no_seeded_counts(): void
    {
        $this->customer();

        $data = $this->getJson('/api/v1/home')->assertOk()->json('data');

        // These were a hardcoded 1 and a count of seeded notifications.
        $this->assertArrayNotHasKey('active_applications', $data);
        $this->assertArrayNotHasKey('unread_notifications', $data);

        // The real counts, for a customer who has done nothing, are zero.
        $this->assertSame(0, $data['applications']['total']);
        $this->assertSame(0, $data['applications']['active']);
        $this->assertSame(0, $data['vault']['document_count']);
        $this->assertSame(0, $data['goals']['total']);
    }

    public function test_home_says_nothing_about_a_customer_it_cannot_measure(): void
    {
        // No income: no score, and no attention items claiming anything.
        $this->customer([
            'monthly_income' => 0,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'savings' => 0,
        ]);

        $data = $this->getJson('/api/v1/home')->assertOk()->json('data');

        $this->assertFalse($data['customer']['has_financial_profile']);
        $this->assertSame('insufficient_data', $data['score']['status']);
        $this->assertNull($data['score']['score']);
        $this->assertSame([], $data['attention']);
        $this->assertSame('complete_profile', $data['primary_action']['key']);
    }

    public function test_every_figure_on_home_comes_from_an_engine(): void
    {
        $user = $this->customer();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ]);

        $home = $this->getJson('/api/v1/home')->assertOk()->json('data');

        // Home's figures are the same ones the dedicated endpoints serve.
        $profile = $this->getJson('/api/v1/financial-profile')->json('data');
        $score = $this->getJson('/api/v1/fynn-score')->json('data');

        $this->assertSame(
            $profile['derived']['surplus'],
            $home['financial']['surplus'],
        );
        $this->assertSame(
            $profile['derived']['emi_ratio'],
            $home['financial']['emi_ratio'],
        );
        $this->assertSame(
            $profile['derived']['emergency_months'],
            $home['financial']['emergency_months'],
        );
        $this->assertSame($score['score'], $home['score']['score']);
        $this->assertSame($score['band'], $home['score']['band']);
    }
}
