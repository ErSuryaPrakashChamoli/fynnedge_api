<?php

namespace Tests\Feature\ProviderEvents;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Domain\ProviderEvents\Fixture\FixtureProviderEventAuthenticator;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\ProviderEvent;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may tell FynnEdge that something happened, and about whose
 * application.
 */
class ProviderEventSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'fixture-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.provider_events.driver' => 'fixture',
            'fynnedge.provider_events.fixture_secret' => self::SECRET,
        ]);
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

    /** @return array{0: string, 1: string} application id, provider reference */
    private function submittedApplication(User $user, string $reference): array
    {
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        config([
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        ApplicationSubmission::latest('id')->first()
            ->update(['provider_reference' => $reference]);

        return [$id, $reference];
    }

    private function deliver(array $body, array $headers = [])
    {
        return $this->withHeaders($headers)
            ->postJson('/api/v1/provider-events/fixture', $body);
    }

    private function event(string $type, string $reference, string $id): array
    {
        return [
            'eventId' => $id,
            'eventType' => $type,
            'submissionRef' => $reference,
            'eventTime' => Carbon::now()->toIso8601String(),
        ];
    }

    private function signed(array $body)
    {
        return $this->deliver($body, [
            FixtureProviderEventAuthenticator::HEADER => self::SECRET,
        ]);
    }

    // --- Authentication ------------------------------------------------------

    public function test_an_unsigned_delivery_is_refused(): void
    {
        $user = $this->customer('Sample Customer');
        [, $reference] = $this->submittedApplication($user, 'GW-1');

        $this->deliver($this->event('APPROVED', $reference, 'E-1'))
            ->assertUnauthorized();

        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_a_wrong_secret_is_refused_and_says_nothing(): void
    {
        $user = $this->customer('Sample Customer');
        [, $reference] = $this->submittedApplication($user, 'GW-1');

        $response = $this->deliver(
            $this->event('APPROVED', $reference, 'E-1'),
            [FixtureProviderEventAuthenticator::HEADER => 'not-the-secret'],
        );

        $response->assertUnauthorized();

        // The reason stays server-side: a caller probing the boundary
        // learns nothing about which check failed.
        foreach (['secret', 'signature', 'hash', 'expected', self::SECRET] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase(
                $leak,
                $response->getContent(),
                $leak,
            );
        }

        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_with_no_secret_configured_nothing_authenticates(): void
    {
        config(['fynnedge.provider_events.fixture_secret' => null]);

        $user = $this->customer('Sample Customer');
        [, $reference] = $this->submittedApplication($user, 'GW-1');

        // No way to authenticate means nothing is accepted. Never the
        // other way round.
        $this->deliver(
            $this->event('APPROVED', $reference, 'E-1'),
            [FixtureProviderEventAuthenticator::HEADER => ''],
        )->assertUnauthorized();
    }

    public function test_a_customer_token_authenticates_no_provider_event(): void
    {
        $user = $this->customer('Sample Customer');
        [$id, $reference] = $this->submittedApplication($user, 'GW-1');

        // A customer, fully signed in, reporting their own approval.
        Sanctum::actingAs($user);

        $this->deliver($this->event('APPROVED', $reference, 'E-1'))
            ->assertUnauthorized();

        $this->assertSame('started', LoanApplication::find($id)->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_an_unknown_provider_is_a_plain_404(): void
    {
        $response = $this->withHeaders([
            FixtureProviderEventAuthenticator::HEADER => self::SECRET,
        ])->postJson('/api/v1/provider-events/some-real-bank', [
            'eventType' => 'APPROVED',
        ]);

        $response->assertNotFound();

        // Whether FynnEdge integrates with a given provider is not
        // something an unauthenticated caller gets to discover.
        foreach (['fixture', 'driver', 'allowlist', 'Normalizer'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent(), $leak);
        }
    }

    public function test_the_provider_code_cannot_name_an_internal_class(): void
    {
        foreach ([
            'App%5CDomain%5CProviderEvents%5CFixture%5CFixtureProviderEventNormalizer',
            '..%2Ffixture',
            'FIXTURE',
        ] as $attempt) {
            $this->withHeaders([
                FixtureProviderEventAuthenticator::HEADER => self::SECRET,
            ])->postJson("/api/v1/provider-events/{$attempt}", [
                'eventType' => 'APPROVED',
            ])->assertNotFound();
        }
    }

    // --- Production guard ----------------------------------------------------

    public function test_the_simulated_source_does_not_exist_in_production(): void
    {
        $user = $this->customer('Sample Customer');
        [$id, $reference] = $this->submittedApplication($user, 'GW-1');

        $this->app['env'] = 'production';

        // Not an authentication failure — the provider is simply not in the
        // allowlist there, so a fixture delivery cannot reach a real
        // customer's application by any route.
        $this->signed($this->event('APPROVED', $reference, 'E-1'))
            ->assertNotFound();

        $this->assertSame('started', LoanApplication::find($id)->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    // --- Resolution ----------------------------------------------------------

    public function test_an_unknown_reference_changes_nothing_and_is_recorded(): void
    {
        $user = $this->customer('Sample Customer');
        [$id] = $this->submittedApplication($user, 'GW-1');

        $this->signed($this->event('APPROVED', 'GW-DOES-NOT-EXIST', 'E-1'))
            ->assertStatus(500);

        // Recorded, because an event that reaches nothing is exactly what
        // an operator needs to find — and applied to nothing.
        $record = ProviderEvent::sole();
        $this->assertSame('failed', $record->processing_status);
        $this->assertSame('unresolved_reference', $record->outcome_reason);
        $this->assertNull($record->loan_application_id);

        $this->assertSame('started', LoanApplication::find($id)->status);
    }

    public function test_an_event_cannot_name_the_customer_it_affects(): void
    {
        $owner = $this->customer('Owner Person');
        [$ownerApplication, $ownerReference] = $this->submittedApplication($owner, 'GW-OWNER');

        $intruder = $this->customer('Intruder Person');
        [$intruderApplication] = $this->submittedApplication($intruder, 'GW-INTRUDER');

        // Everything a delivery might try to smuggle in alongside a
        // reference belonging to somebody else.
        $this->signed(array_merge(
            $this->event('APPROVED', $ownerReference, 'E-1'),
            [
                'user_id' => $intruder->id,
                'customer_id' => $intruder->id,
                'applicationId' => $intruderApplication,
                'loan_application_id' => $intruderApplication,
            ],
        ))->assertAccepted();

        // The reference decided, and only the reference. The owner's
        // application moved; the intruder's did not.
        $this->assertSame('approved', LoanApplication::find($ownerApplication)->status);
        $this->assertSame('started', LoanApplication::find($intruderApplication)->status);
    }

    public function test_a_reference_belonging_to_another_provider_resolves_nothing(): void
    {
        $user = $this->customer('Sample Customer');
        [$id, $reference] = $this->submittedApplication($user, 'GW-1');

        // The submission was made through `fixture`. A different provider
        // quoting the same reference must not match it.
        ApplicationSubmission::latest('id')->first()
            ->update(['provider' => 'some_other_provider']);

        $this->signed($this->event('APPROVED', $reference, 'E-1'))
            ->assertStatus(500);

        $this->assertSame('started', LoanApplication::find($id)->status);
        $this->assertSame('failed', ProviderEvent::sole()->processing_status);
    }

    // --- Customers cannot forge status ---------------------------------------

    public function test_a_customer_cannot_set_their_own_application_status(): void
    {
        $user = $this->customer('Sample Customer');
        [$id] = $this->submittedApplication($user, 'GW-1');

        Sanctum::actingAs($user);

        foreach ([
            ['status' => 'approved'],
            ['status' => 'disbursed'],
            ['application_status' => 'under_review'],
            ['customer_status' => ['code' => 'approved']],
        ] as $payload) {
            $this->patchJson("/api/v1/applications/{$id}", $payload);
            $this->putJson("/api/v1/applications/{$id}", $payload);
        }

        $this->assertSame('started', LoanApplication::find($id)->status);
    }

    public function test_a_customer_cannot_forge_a_submission_outcome(): void
    {
        $user = $this->customer('Sample Customer');
        [$id] = $this->submittedApplication($user, 'GW-1');

        Sanctum::actingAs($user);

        $this->postJson("/api/v1/applications/{$id}/submission", [
            'outcome' => 'provider_received',
            'provider_received' => true,
        ]);

        // Still unknown: only a provider event can resolve it.
        $this->assertSame(
            'unknown',
            ApplicationSubmission::latest('id')->first()->outcome,
        );
    }

    public function test_a_customer_cannot_inject_a_timeline_event(): void
    {
        $user = $this->customer('Sample Customer');
        [$id] = $this->submittedApplication($user, 'GW-1');

        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/applications/{$id}", [
            'events' => [[
                'label' => 'Application approved',
                'detail' => 'The provider has approved your application.',
                'source' => 'provider',
            ]],
        ]);

        $labels = collect(
            $this->getJson("/api/v1/applications/{$id}")->json('data.events')
        )->pluck('label');

        $this->assertFalse($labels->contains('Application approved'));

        // And nothing in the timeline claims to be a provider speaking.
        $sources = collect(
            $this->getJson("/api/v1/applications/{$id}")->json('data.events')
        )->pluck('source')->unique();
        $this->assertFalse($sources->contains('provider'));
    }

    // --- Malformed input -----------------------------------------------------

    public function test_an_unreadable_event_is_refused_rather_than_guessed(): void
    {
        $user = $this->customer('Sample Customer');
        [$id, $reference] = $this->submittedApplication($user, 'GW-1');

        foreach ([
            [],
            ['eventType' => 'SOMETHING_NEW', 'submissionRef' => $reference, 'eventTime' => '2026-09-07T10:00:00Z'],
            ['eventType' => 'APPROVED', 'eventTime' => '2026-09-07T10:00:00Z'],
            ['eventType' => 'APPROVED', 'submissionRef' => $reference],
            ['eventType' => 'APPROVED', 'submissionRef' => $reference, 'eventTime' => 'not a time'],
        ] as $payload) {
            $this->signed($payload)->assertStatus(422);
        }

        $this->assertSame('started', LoanApplication::find($id)->status);
        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_an_oversized_body_is_refused(): void
    {
        $user = $this->customer('Sample Customer');
        [, $reference] = $this->submittedApplication($user, 'GW-1');

        $this->signed(array_merge(
            $this->event('APPROVED', $reference, 'E-1'),
            ['padding' => str_repeat('x', 70000)],
        ))->assertStatus(413);

        $this->assertDatabaseCount('provider_events', 0);
    }

    public function test_the_ingress_is_rate_limited(): void
    {
        config(['fynnedge.provider_events.per_minute' => 3]);

        $user = $this->customer('Sample Customer');
        [, $reference] = $this->submittedApplication($user, 'GW-1');

        for ($i = 0; $i < 3; $i++) {
            $this->signed($this->event('IN_REVIEW', $reference, "E-{$i}"));
        }

        $this->signed($this->event('IN_REVIEW', $reference, 'E-over'))
            ->assertStatus(429);
    }

    // --- Nothing sensitive is stored -----------------------------------------

    public function test_no_provider_payload_or_customer_data_is_stored(): void
    {
        $user = $this->customer('Sample Customer');
        [, $reference] = $this->submittedApplication($user, 'GW-1');

        // A provider callback carrying everything a real one might.
        $this->signed(array_merge(
            $this->event('APPROVED', $reference, 'E-1'),
            [
                'applicantName' => 'Sample Customer',
                'panNumber' => 'AAAAA0000A',
                'aadhaarNumber' => '0000 0000 0000',
                'monthlyIncome' => 150000,
                'bankAccount' => '00000000000',
                'decisionRationale' => 'Long free text from the provider.',
            ],
        ))->assertAccepted();

        $stored = json_encode(ProviderEvent::all()->toArray());

        foreach ([
            'Sample Customer', 'AAAAA0000A', '0000 0000 0000', '150000',
            '00000000000', 'decisionRationale', 'Long free text',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $stored, "persisted: {$leak}");
        }

        // A hash is kept to tell two deliveries apart; the body is not.
        $this->assertNotNull(ProviderEvent::first()->payload_hash);
    }
}
