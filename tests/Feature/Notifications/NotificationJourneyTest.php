<?php

namespace Tests\Feature\Notifications;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Domain\ProviderEvents\Fixture\FixtureProviderEventAuthenticator;
use App\Models\ApplicationSubmission;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a customer is actually told, from things that actually happened.
 *
 *     INTERNAL EVENT  ≠  CUSTOMER NOTIFICATION
 *
 * Every notification here comes out the far end of a real journey — a real
 * application, a real submission, a real provider event through Module 28's
 * ingress. Nothing is seeded and nothing is constructed by hand, because a
 * notification built in a test is a notification whose producer was never
 * checked.
 *
 * NO LIVE PROVIDER IS CONNECTED and NO PUSH PROVIDER IS CONNECTED. The
 * events are simulated; the notifications about them are real records.
 */
class NotificationJourneyTest extends TestCase
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

    private function submitWithUnknownOutcome(string $applicationId): string
    {
        config(['fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value]);

        $this->postJson("/api/v1/applications/{$applicationId}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'unknown');

        $submission = ApplicationSubmission::latest('id')->first();
        $submission->update(['provider_reference' => 'GW-REF-0001']);

        return 'GW-REF-0001';
    }

    private function deliver(string $type, string $reference, string $eventId, ?string $at = null)
    {
        return $this->withHeaders([FixtureProviderEventAuthenticator::HEADER => self::SECRET])
            ->postJson('/api/v1/provider-events/fixture', [
                'eventId' => $eventId,
                'eventType' => $type,
                'submissionRef' => $reference,
                'eventTime' => $at ?? Carbon::now()->toIso8601String(),
            ]);
    }

    /** @return list<array<string, mixed>> */
    private function centre(): array
    {
        return $this->getJson('/api/v1/notifications')->assertOk()->json('data');
    }

    private function ofType(string $type): ?array
    {
        foreach ($this->centre() as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }

        return null;
    }

    // --- Applications ------------------------------------------------------

    public function test_starting_an_application_is_worth_saying_once(): void
    {
        $this->customer();
        $id = $this->application();

        $item = $this->ofType('application_started');

        $this->assertNotNull($item);
        $this->assertSame('application', $item['category']);
        $this->assertSame("fynnedge://applications/{$id}", $item['deep_link']);

        // And it does not read like a submission.
        $this->assertStringContainsString('not been sent anywhere', $item['body']);
    }

    public function test_an_idempotent_repeat_of_creation_tells_them_once(): void
    {
        $this->customer();

        $payload = ['product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60];
        $headers = ['Idempotency-Key' => 'the-same-tap'];

        $this->withHeaders($headers)->postJson('/api/v1/applications', $payload)->assertCreated();
        $this->withHeaders($headers)->postJson('/api/v1/applications', $payload)->assertOk();

        $this->assertSame(1, Notification::where('type', 'application_started')->count());
    }

    public function test_an_unknown_submission_says_so_and_says_not_to_try_again(): void
    {
        $this->customer();
        $id = $this->application();
        $this->submitWithUnknownOutcome($id);

        $item = $this->ofType('submission_needs_confirmation');

        $this->assertNotNull($item);

        // The one case where the honest sentence is uncomfortable, and the
        // one where a reassuring one causes a second application.
        $this->assertStringContainsString('could not confirm', $item['body']);
        $this->assertStringContainsString('do not submit it again', $item['body']);

        // It is not presented as a failure, because no failure is known.
        $this->assertStringNotContainsString('failed', strtolower($item['body']));
    }

    public function test_a_successful_handover_is_not_reported_as_receipt(): void
    {
        $this->customer();
        $id = $this->application();

        config(['fynnedge.lender.fixture_scenario' => FixtureScenario::Simulated->value]);
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        /*
         * HTTP 200 is not receipt. Only a provider event can say the
         * provider has it, so nothing here claims one.
         */
        $this->assertNull($this->ofType('application_received'));
        $this->assertNull($this->ofType('application_under_review'));
    }

    public function test_a_provider_saying_it_received_the_application_is_reported_as_theirs(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('SUBMISSION_ACK', $reference, 'evt_1')->assertStatus(202);

        $item = $this->ofType('application_received');

        $this->assertNotNull($item);
        $this->assertStringContainsString('provider', strtolower($item['title']));
    }

    public function test_under_review_and_action_required_come_only_from_the_provider(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('IN_REVIEW', $reference, 'evt_1')->assertStatus(202);
        $this->assertNotNull($this->ofType('application_under_review'));

        $this->deliver('ACTION_REQUIRED', $reference, 'evt_2')->assertStatus(202);

        $action = $this->ofType('action_required');
        $this->assertNotNull($action);
        $this->assertSame("fynnedge://applications/{$id}", $action['deep_link']);
    }

    public function test_an_approval_is_attributed_to_the_provider(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('APPROVED', $reference, 'evt_1')->assertStatus(202);

        $item = $this->ofType('application_approved');
        $this->assertNotNull($item);

        // Attribution in the title, so it survives a truncated push.
        $this->assertSame('The provider approved your application', $item['title']);

        // FynnEdge approves nothing, and must never appear to.
        $whole = strtolower($item['title'].' '.$item['body']);
        $this->assertStringNotContainsString('fynnedge approved', $whole);
        $this->assertStringNotContainsString('we approved', $whole);
        $this->assertStringNotContainsString('congratulations', $whole);
        $this->assertStringNotContainsString('guaranteed', $whole);
    }

    public function test_a_decline_is_attributed_and_invents_no_reason(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('DECLINED', $reference, 'evt_1')->assertStatus(202);

        $item = $this->ofType('application_declined');
        $this->assertNotNull($item);
        $this->assertStringContainsString('The provider', $item['title']);
        $this->assertStringContainsString('not FynnEdge', $item['body']);

        // No invented reason, and nothing about their creditworthiness.
        foreach (['credit', 'score', 'income', 'because'] as $invention) {
            $this->assertStringNotContainsString($invention, strtolower($item['body']));
        }
    }

    public function test_a_disbursal_claims_no_amount_and_no_date(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('DISBURSED', $reference, 'evt_1')->assertStatus(202);

        $item = $this->ofType('application_disbursed');
        $this->assertNotNull($item);

        // FynnEdge is told that it happened, not what was paid or when it
        // lands. Neither is anything it was given.
        foreach (['1,000,000', '1000000', 'today', 'tomorrow', 'within', 'hours'] as $invention) {
            $this->assertStringNotContainsString($invention, strtolower($item['body']));
        }
    }

    public function test_cancelling_is_reported(): void
    {
        $this->customer();
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();

        $this->assertNotNull($this->ofType('application_cancelled'));
    }

    // --- Ordering and duplicates -------------------------------------------

    public function test_a_redelivered_provider_event_produces_one_notification(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        // The same event id, eight times over, as a provider retrying.
        for ($i = 0; $i < 8; $i++) {
            $this->deliver('APPROVED', $reference, 'evt_same')->assertStatus(202);
        }

        $this->assertSame(1, Notification::where('type', 'application_approved')->count());
    }

    public function test_a_stale_provider_event_creates_no_misleading_notification(): void
    {
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('DISBURSED', $reference, 'evt_1')->assertStatus(202);
        $this->assertNotNull($this->ofType('application_disbursed'));

        // An older event arriving late. Module 28 ignores it; the
        // notification layer inherits that rather than reimplementing it.
        $this->deliver(
            'IN_REVIEW',
            $reference,
            'evt_late',
            Carbon::now()->subDay()->toIso8601String(),
        )->assertStatus(202);

        $this->assertNull(
            $this->ofType('application_under_review'),
            'A stale event announced a review after a disbursal.',
        );
    }

    public function test_a_second_distinct_event_is_a_second_notification(): void
    {
        // The counterpart: dedupe must not be swallowing real events.
        $this->customer();
        $id = $this->application();
        $reference = $this->submitWithUnknownOutcome($id);

        $this->deliver('IN_REVIEW', $reference, 'evt_1')->assertStatus(202);
        $this->deliver('APPROVED', $reference, 'evt_2')->assertStatus(202);

        $this->assertNotNull($this->ofType('application_under_review'));
        $this->assertNotNull($this->ofType('application_approved'));
    }
}
