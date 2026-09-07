<?php

namespace Tests\Feature\ProviderEvents;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Domain\ProviderEvents\Fixture\FixtureProviderEventAuthenticator;
use App\Models\ApplicationSubmission;
use App\Models\ProviderEvent;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A provider telling FynnEdge what happened, and what FynnEdge does with it.
 *
 * NO LIVE PROVIDER EVENT SOURCE IS CONNECTED. Everything here runs against a
 * simulated one, and every event it produces is marked simulated all the way
 * to the customer's timeline.
 */
class ProviderEventJourneyTest extends TestCase
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

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::LenderDataSharing,
            'application_review',
        );

        return $user;
    }

    private function application(): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    /**
     * A submission whose outcome is unknown, and the provider reference the
     * provider would quote when it eventually says something.
     */
    private function submitWithUnknownOutcome(string $applicationId): string
    {
        config([
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $this->postJson("/api/v1/applications/{$applicationId}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'unknown');

        $submission = ApplicationSubmission::latest('id')->first();

        // The fixture provider gives no reference on a timeout — it never
        // answered. A real provider quoting one later is how the two are
        // matched, so the test sets the reference the provider will use.
        $reference = 'GW-REF-0001';
        $submission->update(['provider_reference' => $reference]);

        return $reference;
    }

    private function deliver(
        string $type,
        string $reference,
        string $eventId,
        ?string $at = null,
    ) {
        return $this->withHeaders([
            FixtureProviderEventAuthenticator::HEADER => self::SECRET,
        ])->postJson('/api/v1/provider-events/fixture', [
            'eventId' => $eventId,
            'eventType' => $type,
            'submissionRef' => $reference,
            'eventTime' => $at ?? Carbon::now()->toIso8601String(),
        ]);
    }

    private function applicationState(string $applicationId): array
    {
        return $this->getJson("/api/v1/applications/{$applicationId}")
            ->assertOk()
            ->json('data');
    }

    // --- The whole journey ---------------------------------------------------

    public function test_an_unknown_outcome_is_resolved_by_a_provider_event(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        // Before: FynnEdge does not know, and says exactly that.
        $before = $this->applicationState($id);
        $this->assertSame('submission_unconfirmed', $before['customer_status']['code']);
        $this->assertStringContainsString(
            'could not confirm',
            $before['customer_status']['description'],
        );

        // The customer is not invited to try again.
        $this->assertStringNotContainsStringIgnoringCase(
            'try again',
            $before['customer_status']['description'],
        );

        // The provider speaks.
        $this->deliver('SUBMISSION_ACK', $reference, 'E-001')->assertAccepted();

        $after = $this->applicationState($id);
        $this->assertSame('provider_received', $after['customer_status']['code']);
        $this->assertSame(
            'provider_received',
            ApplicationSubmission::latest('id')->first()->outcome,
        );

        // And the application's own status has not been dragged along with
        // it: receipt is about the delivery, not about a decision.
        $this->assertSame('started', $after['status']);
    }

    public function test_the_full_state_machine_walks_forward(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $steps = [
            ['SUBMISSION_ACK', 'E-001', 'provider_received', 'started'],
            ['IN_REVIEW', 'E-002', 'under_review', 'under_review'],
            ['ACTION_REQUIRED', 'E-003', 'action_needed', 'action_needed'],
            ['APPROVED', 'E-004', 'approved', 'approved'],
            ['DISBURSED', 'E-005', 'disbursed', 'disbursed'],
        ];

        foreach ($steps as $i => [$type, $eventId, $customerCode, $applicationStatus]) {
            $this->deliver(
                $type,
                $reference,
                $eventId,
                Carbon::now()->addMinutes($i)->toIso8601String(),
            )->assertAccepted();

            $data = $this->applicationState($id);

            $this->assertSame($customerCode, $data['customer_status']['code'], $type);
            $this->assertSame($applicationStatus, $data['status'], $type);
        }

        // Every event applied, and each left one entry in the timeline.
        $this->assertSame(5, ProviderEvent::where('processing_status', 'processed')->count());

        $labels = collect($this->applicationState($id)['events'])->pluck('label');
        foreach ([
            'Application received', 'Application under review',
            'Action needed from you', 'Application approved', 'Loan disbursed',
        ] as $label) {
            $this->assertSame(1, $labels->filter(fn ($l) => $l === $label)->count(), $label);
        }
    }

    public function test_an_unknown_outcome_can_also_be_resolved_as_a_rejection(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('SUBMISSION_REJECTED', $reference, 'E-010')->assertAccepted();

        $data = $this->applicationState($id);

        $this->assertSame('rejected_by_gateway', $data['customer_status']['code']);
        $this->assertSame(
            'rejected_by_gateway',
            ApplicationSubmission::latest('id')->first()->outcome,
        );

        // A gateway refusing to forward is not a lender declining to lend,
        // and the wording says so.
        $this->assertStringContainsString(
            'not a decision about lending to you',
            $data['customer_status']['description'],
        );
        $this->assertSame('started', $data['status']);
    }

    // --- Idempotency ---------------------------------------------------------

    public function test_the_same_event_many_times_changes_things_once(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        for ($i = 0; $i < 8; $i++) {
            $this->deliver('SUBMISSION_ACK', $reference, 'E-001')->assertAccepted();
        }

        // One row, one transition, one timeline entry.
        $this->assertSame(1, ProviderEvent::count());
        $this->assertSame('processed', ProviderEvent::first()->processing_status);

        $received = collect($this->applicationState($id)['events'])
            ->filter(fn ($e) => $e['label'] === 'Application received');
        $this->assertCount(1, $received);
    }

    public function test_an_event_with_no_id_is_still_deduplicated(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $at = Carbon::now()->toIso8601String();

        // No eventId: the fingerprint of the identifying facts stands in,
        // and is stable across redeliveries of the same event.
        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders([
                FixtureProviderEventAuthenticator::HEADER => self::SECRET,
            ])->postJson('/api/v1/provider-events/fixture', [
                'eventType' => 'SUBMISSION_ACK',
                'submissionRef' => $reference,
                'eventTime' => $at,
            ])->assertAccepted();
        }

        $this->assertSame(1, ProviderEvent::count());
    }

    // --- Ordering and conflict -----------------------------------------------

    public function test_a_late_event_cannot_undo_a_later_one(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('APPROVED', $reference, 'E-020')->assertAccepted();
        $this->assertSame('approved', $this->applicationState($id)['status']);

        // "Under review" turns up afterwards. It cannot un-approve.
        $this->deliver('IN_REVIEW', $reference, 'E-021')->assertAccepted();

        $this->assertSame('approved', $this->applicationState($id)['status']);

        $ignored = ProviderEvent::where('event_type', 'application_under_review')->first();
        $this->assertSame('ignored', $ignored->processing_status);
        $this->assertSame('stale_event', $ignored->outcome_reason);

        // And the customer's timeline was not touched by it.
        $labels = collect($this->applicationState($id)['events'])->pluck('label');
        $this->assertFalse($labels->contains('Application under review'));
    }

    public function test_a_contradictory_decision_does_not_flip_the_outcome(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('APPROVED', $reference, 'E-030')->assertAccepted();
        $this->deliver('DECLINED', $reference, 'E-031')->assertAccepted();

        // The recorded decision stands. Two contradictory terminal events
        // cannot both be true, and a customer's outcome must not depend on
        // delivery order.
        $this->assertSame('approved', $this->applicationState($id)['status']);

        $declined = ProviderEvent::where('event_type', 'application_declined')->first();
        $this->assertSame('ignored', $declined->processing_status);
        $this->assertSame('conflicting_or_repeated_stage', $declined->outcome_reason);
    }

    public function test_a_disbursal_cannot_be_walked_back(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('APPROVED', $reference, 'E-040')->assertAccepted();
        $this->deliver('DISBURSED', $reference, 'E-041')->assertAccepted();
        $this->assertSame('disbursed', $this->applicationState($id)['status']);

        foreach (['DECLINED', 'IN_REVIEW', 'ACTION_REQUIRED'] as $i => $type) {
            $this->deliver($type, $reference, "E-05{$i}")->assertAccepted();
            $this->assertSame('disbursed', $this->applicationState($id)['status'], $type);
        }

        $this->assertSame(2, ProviderEvent::where('processing_status', 'processed')->count());
        $this->assertSame(3, ProviderEvent::where('processing_status', 'ignored')->count());
    }

    public function test_eight_competing_events_produce_one_transition(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        // Eight distinct approvals, as a provider retrying with new ids
        // would send them. Each goes through the same lock-and-decide path
        // a concurrent delivery would.
        for ($i = 0; $i < 8; $i++) {
            $this->deliver('APPROVED', $reference, "E-06{$i}")->assertAccepted();
        }

        $this->assertSame('approved', $this->applicationState($id)['status']);

        $this->assertSame(1, ProviderEvent::where('processing_status', 'processed')->count());
        $this->assertSame(7, ProviderEvent::where('processing_status', 'ignored')->count());

        $approved = collect($this->applicationState($id)['events'])
            ->filter(fn ($e) => $e['label'] === 'Application approved');
        $this->assertCount(1, $approved);
    }

    // --- What a provider event must not do -----------------------------------

    public function test_an_event_reaches_nothing_but_the_application_status(): void
    {
        $user = $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $before = [
            'score' => $this->getJson('/api/v1/fynn-score')->json('data'),
            'profile' => $this->getJson('/api/v1/financial-profile')->json('data'),
            'documents' => $this->getJson("/api/v1/applications/{$id}/documents")->json('data'),
            'emi' => $this->applicationState($id)['emi'],
        ];

        foreach ([['SUBMISSION_ACK', 'E-070'], ['IN_REVIEW', 'E-071'], ['APPROVED', 'E-072']] as $i => [$t, $e]) {
            $this->deliver($t, $reference, $e, Carbon::now()->addMinutes($i)->toIso8601String())
                ->assertAccepted();
        }

        // No OCR, no KYC, no bureau pull, no score movement, no repricing,
        // and document readiness untouched.
        $this->assertSame($before['score'], $this->getJson('/api/v1/fynn-score')->json('data'));
        $this->assertSame(
            $before['profile'],
            $this->getJson('/api/v1/financial-profile')->json('data'),
        );
        $this->assertSame(
            $before['documents'],
            $this->getJson("/api/v1/applications/{$id}/documents")->json('data'),
        );
        $this->assertSame($before['emi'], $this->applicationState($id)['emi']);

        $this->assertDatabaseCount('document_scans', 0);
        $this->assertDatabaseCount('kyc_verifications', 0);
        $this->assertDatabaseCount('credit_reports', 0);
        $this->assertSame(0, $user->documentScans()->count());
    }

    public function test_a_simulated_event_is_never_shown_as_a_providers_word(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('SUBMISSION_ACK', $reference, 'E-080')->assertAccepted();

        $event = collect($this->applicationState($id)['events'])
            ->firstWhere('label', 'Application received');

        // The simulated source is carried all the way through, so the app
        // renders it differently and nobody reads a fixture as a lender.
        $this->assertSame('simulated', $event['source']);
        $this->assertTrue(ProviderEvent::first()->is_simulated);
    }

    public function test_nothing_about_the_event_record_reaches_the_customer(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('SUBMISSION_ACK', $reference, 'E-090')->assertAccepted();

        $body = $this->getJson("/api/v1/applications/{$id}")->getContent();

        foreach ([
            'idempotency_key', 'payload_hash', 'E-090', 'eventId',
            'X-FynnEdge', self::SECRET, 'processing_status',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }
    }
}
