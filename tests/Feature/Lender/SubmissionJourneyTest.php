<?php

namespace Tests\Feature\Lender;

use App\Models\ApplicationSubmission;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Home → Explore → FynnMatch → Detail → Trust → Twin → Mirror → Apply →
 * review → consent → submit.
 *
 * And the half that matters: what the customer is told at the end of it when
 * no lender exists.
 */
class SubmissionJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.lender.driver' => 'fixture']);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update([
            'full_name' => 'Sample Customer',
            'city' => 'Pune',
            'age' => 34,
        ]);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_the_whole_journey_ends_in_an_honest_simulated_state(): void
    {
        $user = $this->customer();

        // 1-3. Home, Explore, and the match that leads to a product.
        $this->getJson('/api/v1/home')->assertOk();
        $this->getJson('/api/v1/products')->assertOk();

        $match = $this->getJson(
            '/api/v1/fynn-match?amount=1000000&tenure_months=60&purpose=business'
        )->assertOk();

        $productId = collect($match->json('data.matches'))
            ->first(fn ($m) => $m['product']['id'] === 'prod_bl_b')['product']['id'];

        // 4-6. Detail, Trust, Twin, Mirror — the decision surfaces.
        $this->getJson(
            "/api/v1/fynn-match/{$productId}?amount=1000000&tenure_months=60&purpose=business"
        )->assertOk();
        $this->getJson("/api/v1/products/{$productId}")->assertOk();
        $this->getJson('/api/v1/fynn-twin')->assertOk();

        // 7. Apply — a FynnEdge application, and nothing more than that.
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => $productId,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        // 8. The review screen's data: where it would go, before consent.
        $review = $this->getJson("/api/v1/applications/{$id}/submission")->assertOk();

        $review->assertJsonPath('data.submission.outcome', 'not_attempted')
            ->assertJsonPath('data.submission.reason', 'submission_consent_required')
            ->assertJsonPath('data.destination.is_simulated', true);

        // The destination is named, and named as what it actually is.
        $this->assertSame(
            'FynnEdge Simulated Provider',
            $review->json('data.destination.display_name'),
        );
        $this->assertSame('simulated', $review->json('data.destination.role'));

        // 9. Consent, with the destination in front of them.
        $this->postJson('/api/v1/consents', [
            'type' => 'lender_data_sharing',
            'surface' => 'application_review',
        ])->assertSuccessful();

        // 10. Submit.
        $result = $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertOk();

        // 11. And the truth, in the response and in the timeline.
        $result->assertJsonPath('data.submission.outcome', 'simulated')
            ->assertJsonPath('data.submission.is_simulated', true)
            ->assertJsonPath('data.submission.provider_received', false);

        $this->assertStringContainsString(
            'No lender or provider received this application',
            $result->json('data.disclaimer'),
        );

        // 12. FynnFlow — the application's own history says the same.
        $events = $this->getJson("/api/v1/applications/{$id}")
            ->assertOk()
            ->json('data.events');

        $submissionEvent = collect($events)
            ->first(fn ($e) => str_contains($e['label'], 'Simulated'));

        $this->assertNotNull($submissionEvent);
        $this->assertSame('simulated', $submissionEvent['source']);
        $this->assertStringContainsString(
            'No lender or provider received',
            $submissionEvent['detail'],
        );

        // 13. And nothing about the customer changed on the way through.
        $this->assertSame(
            '150000.00',
            (string) $user->financialProfile->fresh()->monthly_income,
        );
        $this->assertSame(1, ApplicationSubmission::count());
    }

    public function test_the_journey_without_a_provider_never_implies_a_lender(): void
    {
        config(['fynnedge.lender.driver' => 'none']);
        $this->customer();

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        // Module 9's own submit still works: the customer has finished
        // their application in FynnEdge, which is true.
        $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        $body = $this->getJson("/api/v1/applications/{$id}/submission")
            ->assertOk()
            ->getContent();

        // Nothing anywhere suggests a lender has it.
        foreach ([
            'lender received', 'received by lender', 'sent to lender',
            'submitted to lender', 'approved', 'under review', 'eligible',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $body, $claim);
        }

        $this->assertStringContainsString('has not been sent anywhere', $body);
        $this->assertStringContainsString('nothing about it has failed', $body);
    }
}
