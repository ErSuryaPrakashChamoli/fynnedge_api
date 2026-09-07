<?php

namespace Tests\Feature\Lender;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureLenderSubmissionProvider;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Domain\Lender\LenderSubmissionProvider;
use App\Domain\Lender\ProviderSubmissionResult;
use App\Domain\Lender\SubmissionFieldMap;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sending an application, and what sending is not.
 *
 * NO LIVE LENDER SUBMISSION PROVIDER IS CONNECTED. Everything here runs
 * against a fictional one that sends nothing anywhere. No real lender, real
 * gateway or real customer data is involved.
 */
class LenderSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fynnedge.lender.driver' => 'fixture']);
    }

    private function scenario(FixtureScenario $scenario): void
    {
        // Server-side configuration is the only way to choose behaviour.
        // There is deliberately no request parameter that can do this.
        config(['fynnedge.lender.fixture_scenario' => $scenario->value]);
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

    private function consent(User $user): void
    {
        app(ConsentService::class)->grant(
            $user,
            ConsentType::LenderDataSharing,
            'application_review',
        );
    }

    private function application(): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    // --- The central distinction --------------------------------------------

    public function test_a_fynnedge_application_is_not_a_lender_application(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        // Module 9's submit: the customer completed their application here.
        $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk()->assertJsonPath('data.status', 'submitted');

        // And no provider has heard of it.
        $state = $this->getJson("/api/v1/applications/{$id}/submission")->assertOk();

        $state->assertJsonPath('data.application.status', 'submitted')
            ->assertJsonPath('data.submission.outcome', 'not_attempted')
            ->assertJsonPath('data.submission.provider_received', false);

        $this->assertDatabaseCount('application_submissions', 0);
    }

    public function test_an_http_200_from_fynnedge_is_not_provider_receipt(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        // FynnEdge answers 200. That is FynnEdge saying "I handled your
        // request", and nothing more.
        $response = $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertOk();

        $this->assertFalse(
            $response->json('data.submission.provider_received'),
            'a 200 from FynnEdge was recorded as a provider receiving the '
            .'application',
        );

        $this->assertSame('simulated', $response->json('data.submission.outcome'));
        $this->assertTrue($response->json('data.submission.is_simulated'));

        // And the stored attempt agrees.
        $this->assertFalse(ApplicationSubmission::first()->providerHasIt());
    }

    public function test_a_fixture_can_never_report_provider_receipt(): void
    {
        $user = $this->customer();
        $this->consent($user);

        // Every scenario the fictional provider has.
        foreach (FixtureScenario::cases() as $scenario) {
            $this->scenario($scenario);
            $id = $this->application();

            $this->postJson("/api/v1/applications/{$id}/submission");

            $submission = ApplicationSubmission::latest('id')->first();

            $this->assertNotNull($submission, $scenario->value);
            $this->assertFalse(
                $submission->providerHasIt(),
                "{$scenario->value} claimed a provider received it",
            );
            $this->assertNotSame(
                'provider_received',
                $submission->outcome,
                $scenario->value,
            );
            $this->assertNull($submission->provider_reference, $scenario->value);
        }
    }

    public function test_no_simulated_outcome_is_ever_an_approval(): void
    {
        $user = $this->customer();
        $this->consent($user);

        foreach (FixtureScenario::cases() as $scenario) {
            $this->scenario($scenario);
            $id = $this->application();

            $body = $this->postJson("/api/v1/applications/{$id}/submission")
                ->getContent();

            foreach ([
                'approved', 'declined', 'disbursed', 'under_review',
                'sanctioned', 'you qualify', 'eligible', 'guaranteed',
                'received by lender', 'lender received',
            ] as $claim) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $claim,
                    $body,
                    "{$scenario->value} said: {$claim}",
                );
            }

            // The application's own status never moves to a provider one.
            $this->assertFalse(
                LoanApplication::find($id)->status()->isProviderDecision(),
                $scenario->value,
            );
        }
    }

    // --- Every provider behaviour -------------------------------------------

    #[DataProvider('scenarios')]
    public function test_every_provider_behaviour_produces_an_honest_outcome(
        FixtureScenario $scenario,
        string $expectedOutcome,
        bool $canRetry,
    ): void {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario($scenario);

        $id = $this->application();
        $data = $this->postJson("/api/v1/applications/{$id}/submission")
            ->json('data');

        $this->assertSame($expectedOutcome, $data['submission']['outcome']);
        $this->assertFalse($data['submission']['provider_received']);
        $this->assertSame(
            $canRetry,
            $data['submission']['can_retry'],
            $scenario->value,
        );
    }

    public static function scenarios(): array
    {
        return [
            'simulated acceptance' => [
                FixtureScenario::Simulated, 'simulated', false,
            ],
            'the gateway refused it' => [
                FixtureScenario::RejectedByGateway, 'rejected_by_gateway', true,
            ],
            'the gateway is down' => [
                FixtureScenario::ProviderUnavailable, 'failed', true,
            ],
            'the gateway is rate limiting' => [
                FixtureScenario::RateLimited, 'failed', true,
            ],

            // The one that matters: a request that left and was never
            // answered is not a failure, and must not offer a retry.
            'no answer after sending' => [
                FixtureScenario::TimeoutAfterSend, 'unknown', false,
            ],
        ];
    }

    public function test_an_unanswered_request_is_unknown_not_failed(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::TimeoutAfterSend);

        $id = $this->application();
        $data = $this->postJson("/api/v1/applications/{$id}/submission")
            ->json('data');

        $this->assertSame('unknown', $data['submission']['outcome']);
        $this->assertTrue($data['submission']['needs_reconciliation']);

        // No retry button, because a retry could create a second
        // application at a provider that may already hold this one.
        $this->assertFalse($data['submission']['can_retry']);

        // And the customer is told the truth about it.
        $this->assertStringContainsString(
            'could not confirm',
            $data['submission']['message'],
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'failed',
            $data['submission']['message'],
        );
    }

    public function test_an_unknown_outcome_refuses_a_second_attempt(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::TimeoutAfterSend);

        $id = $this->application();
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        // Trying again returns where it stands rather than sending a second
        // copy to a provider that may already have one.
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $this->assertSame(1, ApplicationSubmission::count());
    }

    public function test_a_failure_is_retryable_and_makes_a_new_attempt(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::ProviderUnavailable);

        $id = $this->application();
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        // Nothing left FynnEdge, so trying again is safe and is a distinct
        // attempt rather than an overwrite of the first.
        $this->scenario(FixtureScenario::Simulated);
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $attempts = ApplicationSubmission::orderBy('attempt')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('failed', $attempts[0]->outcome);
        $this->assertSame('simulated', $attempts[1]->outcome);
    }

    // --- Consent -------------------------------------------------------------

    public function test_creating_an_application_is_not_permission_to_send_it(): void
    {
        $this->customer();
        $id = $this->application();

        $this->getJson("/api/v1/applications/{$id}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.reason', 'submission_consent_required');

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertForbidden()
            ->assertJsonPath('reason', 'submission_consent_required');

        $this->assertDatabaseCount('application_submissions', 0);
    }

    public function test_without_consent_no_provider_is_called(): void
    {
        $this->customer();
        $id = $this->application();

        $spy = new class extends FixtureLenderSubmissionProvider
        {
            public int $calls = 0;

            public function submit($payload, string $idempotencyKey): ProviderSubmissionResult
            {
                $this->calls++;

                return parent::submit($payload, $idempotencyKey);
            }
        };

        $this->app->instance(LenderSubmissionProvider::class, $spy);

        $this->postJson("/api/v1/applications/{$id}/submission")->assertForbidden();

        $this->assertSame(
            0,
            $spy->calls,
            'an application was sent to a provider without consent',
        );
    }

    public function test_the_consent_is_not_offered_without_a_provider(): void
    {
        config(['fynnedge.lender.driver' => 'none']);
        $this->customer();

        $consents = collect($this->getJson('/api/v1/consents')->json('data.consents'));
        $sharing = $consents->firstWhere('type', 'lender_data_sharing');

        $this->assertFalse($sharing['is_available']);
        $this->assertNull($sharing['current_version']);

        $this->postJson('/api/v1/consents', [
            'type' => 'lender_data_sharing',
        ])->assertStatus(422);
    }

    public function test_no_provider_says_unavailable_not_failed(): void
    {
        config(['fynnedge.lender.driver' => 'none']);
        $this->customer();
        $id = $this->application();

        $state = $this->getJson("/api/v1/applications/{$id}/submission")->assertOk();
        $state->assertJsonPath('data.submission.outcome', 'unavailable')
            ->assertJsonPath('data.submission.reason', 'lender_provider_unavailable');

        $body = $state->getContent();
        $this->assertStringContainsString('nothing about it has failed', $body);

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(501)
            ->assertJsonPath('reason', 'lender_provider_unavailable');

        $this->assertDatabaseCount('application_submissions', 0);
    }

    // --- What actually leaves ------------------------------------------------

    public function test_the_payload_carries_exactly_the_documented_fields(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $sent = ApplicationSubmission::first()->fields_sent;

        // Every field that left is documented with a purpose, and every
        // documented field left. Adding one without justifying it fails here.
        $this->assertSame(SubmissionFieldMap::sentKeys(), $sent);
    }

    public function test_nothing_fynnedge_holds_beyond_the_map_is_sent(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $sent = ApplicationSubmission::first()->fields_sent;

        // Each of these is refused for a written reason.
        foreach (array_keys(SubmissionFieldMap::REFUSED) as $refused) {
            $this->assertNotContains($refused, $sent, "sent: {$refused}");
        }

        // And nothing resembling them by another name.
        foreach ([
            'score', 'fynn_score', 'trust', 'savings', 'monthly_expenses',
            'goals', 'credit', 'cibil', 'kyc', 'aadhaar', 'pan', 'document',
            'password', 'token', 'storage_key', 'user_id',
        ] as $forbidden) {
            foreach ($sent as $field) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $field,
                    "a field named {$field} left FynnEdge",
                );
            }
        }
    }

    public function test_no_outbound_payload_values_are_stored(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        // Field names, never values. FynnEdge does not keep a second copy of
        // a customer's income and phone number for the sake of debugging.
        $stored = json_encode(ApplicationSubmission::all()->toArray());

        foreach (['Sample Customer', '150000', $user->mobile, 'Pune'] as $value) {
            $this->assertStringNotContainsString(
                (string) $value,
                $stored,
                "a payload value was persisted: {$value}",
            );
        }
    }

    public function test_the_product_code_is_translated_not_assumed(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $submission = ApplicationSubmission::first();

        // FynnEdge calls it prod_bl_b; the provider calls it BL-002.
        $this->assertSame('prod_bl_b', $submission->product_id);
        $this->assertSame('BL-002', $submission->provider_product_code);
        $this->assertNotSame(
            $submission->product_id,
            $submission->provider_product_code,
        );
    }

    public function test_a_product_with_no_provider_mapping_is_not_submitted(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        LoanApplication::find($id)->forceFill([
            'product_id' => 'unmapped_product',
        ])->save();

        // An operational gap, not a failure of the customer's application —
        // and never a guessed product code.
        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'provider_mapping_missing');

        $this->assertDatabaseCount('application_submissions', 0);
    }

    // --- The FynnEdge reference survives ------------------------------------

    public function test_the_fynnedge_reference_is_never_replaced(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->application();

        $reference = LoanApplication::find($id)->reference;
        $this->assertMatchesRegularExpression('/^FE-\d{4}-\d{6}$/', $reference);

        $data = $this->postJson("/api/v1/applications/{$id}/submission")
            ->json('data');

        $this->assertSame($reference, $data['application']['reference']);
        $this->assertSame($reference, LoanApplication::find($id)->reference);
    }
}
