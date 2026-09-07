<?php

namespace Tests\Feature\Lender;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\LenderSubmissionProvider;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose application, whose decision, and what a request cannot decide.
 */
class SubmissionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fynnedge.lender.driver' => 'fixture']);
    }

    private function customer(string $name): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => $name, 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::LenderDataSharing,
            'application_review',
        );

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function application(): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    // --- Ownership ----------------------------------------------------------

    public function test_another_customers_application_cannot_be_submitted(): void
    {
        $owner = $this->customer('Owner Person');
        $this->as($owner);
        $id = $this->application();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);

        // The same answer as an application that never existed, and no
        // provider call.
        foreach ([
            ['get', "/api/v1/applications/{$id}/submission"],
            ['post', "/api/v1/applications/{$id}/submission"],
        ] as [$verb, $uri]) {
            $response = $verb === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri);

            $this->assertSame(404, $response->getStatusCode(), $uri);
            $this->assertStringNotContainsString(
                'FE-2026',
                $response->getContent(),
            );
        }

        $this->assertDatabaseCount('application_submissions', 0);
    }

    public function test_a_forged_identity_in_the_payload_reaches_nothing(): void
    {
        $owner = $this->customer('Owner Person');
        $this->as($owner);
        $ownerApplication = $this->application();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission", [
            'user_id' => $owner->id,
            'customer_id' => $owner->id,
            'application_id' => $ownerApplication,
            'actor_id' => $owner->id,
        ])->assertOk();

        // Exactly one submission, belonging to the token holder, for their
        // own application.
        $submissions = ApplicationSubmission::all();
        $this->assertCount(1, $submissions);
        $this->assertSame($intruder->id, $submissions[0]->user_id);
        $this->assertSame((int) $id, $submissions[0]->loan_application_id);
    }

    public function test_every_submission_route_requires_a_session(): void
    {
        foreach ([
            ['getJson', '/api/v1/applications/1/submission'],
            ['postJson', '/api/v1/applications/1/submission'],
        ] as [$verb, $uri]) {
            $response = $verb === 'getJson'
                ? $this->getJson($uri)
                : $this->postJson($uri);

            $this->assertSame(401, $response->getStatusCode(), $uri);
        }
    }

    // --- A client cannot author a submission result -------------------------

    public function test_a_customer_cannot_manufacture_provider_acknowledgement(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission", [
            // Everything a client might try to author.
            'status' => 'provider_received',
            'outcome' => 'provider_received',
            'provider_received' => true,
            'is_simulated' => false,
            'provider' => 'real-provider',
            'provider_reference' => 'FORGED-REF-0001',
            'product_code' => 'PL-999',
        ])->assertOk();

        $submission = ApplicationSubmission::first();

        // The provider's answer, labelled as the simulation it is.
        $this->assertSame('simulated', $submission->outcome);
        $this->assertTrue($submission->is_simulated);
        $this->assertSame('fixture', $submission->provider);
        $this->assertNull($submission->provider_reference);
        $this->assertSame('BL-002', $submission->provider_product_code);
        $this->assertFalse($submission->providerHasIt());
    }

    public function test_a_request_cannot_choose_the_provider_or_the_outcome(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);

        foreach ([
            '?provider=real',
            '?provider=fixture',
            '?driver=real',
            '?scenario=simulated',
            '?fixture_scenario=simulated',
            '?is_simulated=0',
            '?outcome=provider_received',
            '?user_id=1',
        ] as $query) {
            $id = $this->application();
            $this->postJson("/api/v1/applications/{$id}/submission{$query}");

            $submission = ApplicationSubmission::latest('id')->first();

            $this->assertTrue($submission->is_simulated, $query);
            $this->assertSame('fixture', $submission->provider, $query);
            $this->assertFalse($submission->providerHasIt(), $query);
        }
    }

    public function test_the_application_status_cannot_be_forged(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission", [
            'status' => 'approved',
        ])->assertOk();

        // A submission never writes a provider decision, whatever is asked.
        $application = LoanApplication::find($id);
        $this->assertFalse($application->status()->isProviderDecision());
        $this->assertSame('started', $application->status);
    }

    // --- No fallback --------------------------------------------------------

    public function test_a_real_driver_without_an_adapter_refuses_rather_than_simulating(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        config(['fynnedge.lender.driver' => 'some_real_partner']);

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(501)
            ->assertJsonPath('reason', 'lender_provider_unavailable');

        $this->assertDatabaseCount('application_submissions', 0);
    }

    public function test_the_simulated_provider_refuses_to_run_in_production(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        // The one fixture whose output could make a customer believe they
        // have applied for a loan. It does not exist in a production build.
        $this->app['env'] = 'production';

        $this->assertFalse(app(LenderSubmissionProvider::class)->isAvailable());

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(501)
            ->assertJsonPath('reason', 'lender_provider_unavailable');

        $this->assertDatabaseCount('application_submissions', 0);
    }

    // --- One tap, one submission --------------------------------------------

    public function test_three_taps_are_one_submission(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();
        }

        $this->assertSame(1, ApplicationSubmission::count());
    }

    public function test_eight_concurrent_submissions_produce_one(): void
    {
        // Raised so this exercises the lock rather than the limiter. The
        // limiter is a second line of defence and is tested separately;
        // duplicate prevention must not depend on it.
        config(['fynnedge.lender.submissions_per_hour' => 100]);

        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        // Sequential in a test process, but every one goes through the same
        // lock-and-claim path a concurrent request would. A disabled button
        // in Flutter is not what makes this safe.
        $responses = [];

        for ($i = 0; $i < 8; $i++) {
            $responses[] = $this->postJson("/api/v1/applications/{$id}/submission");
        }

        foreach ($responses as $response) {
            $this->assertSame(200, $response->getStatusCode());
        }

        // One submission, one attempt number, one outcome.
        $this->assertSame(1, ApplicationSubmission::count());
        $this->assertSame(1, ApplicationSubmission::first()->attempt);

        // And one entry in the customer's history, not eight.
        $events = LoanApplication::find($id)->events()
            ->where('label', 'Simulated — not sent to any provider')
            ->count();
        $this->assertSame(1, $events);
    }

    public function test_the_submission_limiter_is_the_strictest_in_the_api(): void
    {
        config(['fynnedge.lender.submissions_per_hour' => 2]);

        $user = $this->customer('Sample Customer');
        $this->as($user);

        $first = $this->application();
        $this->postJson("/api/v1/applications/{$first}/submission")->assertOk();

        $second = $this->application();
        $this->postJson("/api/v1/applications/{$second}/submission")->assertOk();

        $third = $this->application();
        $this->postJson("/api/v1/applications/{$third}/submission")
            ->assertStatus(429);

        // Reading the state is free and stays available: it sends nothing.
        $this->getJson("/api/v1/applications/{$third}/submission")->assertOk();
    }

    // --- Nothing internal leaks ---------------------------------------------

    public function test_no_secret_storage_key_or_internal_detail_leaks(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $id = $this->application();

        $body = $this->postJson("/api/v1/applications/{$id}/submission")
            ->getContent();

        foreach ([
            'idempotency_key', 'api_key', 'secret', 'token', 'endpoint',
            'storage_key', 'password', 'Stack trace', 'FixtureLender',
            'App\\Domain', 'user_id',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body, $internal);
        }
    }
}
