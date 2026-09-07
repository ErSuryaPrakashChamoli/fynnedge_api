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
 * Events that could not be applied, tried again.
 *
 * The race this exists for is real: a provider can call back before
 * FynnEdge has stored the reference the callback quotes. Module 28 recorded
 * that failure for an operator and nothing ever looked at it again.
 */
class ProviderEventRetryTest extends TestCase
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

    /** An application submitted with an outcome nobody could confirm. */
    private function submittedApplication(): string
    {
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        config([
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        return $id;
    }

    private function deliver(string $type, string $reference, string $eventId)
    {
        return $this->withHeaders([
            FixtureProviderEventAuthenticator::HEADER => self::SECRET,
        ])->postJson('/api/v1/provider-events/fixture', [
            'eventId' => $eventId,
            'eventType' => $type,
            'submissionRef' => $reference,
            'eventTime' => Carbon::now()->toIso8601String(),
        ]);
    }

    // --- The race -----------------------------------------------------------

    public function test_an_event_that_arrived_too_early_is_resolved_by_a_retry(): void
    {
        $this->customer();
        $id = $this->submittedApplication();

        // The provider calls back before FynnEdge has recorded the
        // reference. Nothing can be attached to it yet.
        $this->deliver('SUBMISSION_ACK', 'GW-LATE', 'E-1')->assertStatus(500);

        $record = ProviderEvent::sole();
        $this->assertSame('failed', $record->processing_status);
        $this->assertSame('unresolved_reference', $record->outcome_reason);
        $this->assertSame(0, $record->retry_count);

        // The reference turns up.
        ApplicationSubmission::latest('id')->first()
            ->update(['provider_reference' => 'GW-LATE']);

        $this->artisan('fynn:provider-events:retry --apply')->assertSuccessful();

        // The same row, now applied — not a second row, because it is the
        // same event.
        $this->assertSame(1, ProviderEvent::count());

        $after = ProviderEvent::sole();
        $this->assertSame('processed', $after->processing_status);
        $this->assertSame(1, $after->retry_count);
        $this->assertNotNull($after->last_retried_at);
        $this->assertNotNull($after->loan_application_id);

        // And the customer's application actually moved.
        $this->assertSame(
            'provider_received',
            ApplicationSubmission::latest('id')->first()->outcome,
        );
    }

    public function test_a_retry_that_still_finds_nothing_records_the_attempt(): void
    {
        $this->customer();
        $this->submittedApplication();

        $this->deliver('SUBMISSION_ACK', 'GW-NEVER', 'E-1')->assertStatus(500);

        $this->artisan('fynn:provider-events:retry --apply')->assertSuccessful();
        $this->artisan('fynn:provider-events:retry --apply')->assertSuccessful();

        $record = ProviderEvent::sole();

        // Still failed, and the count says how hard we have looked — which
        // is the difference between a race worth waiting out and something
        // permanently broken.
        $this->assertSame('failed', $record->processing_status);
        $this->assertSame(2, $record->retry_count);
    }

    public function test_a_dry_run_retries_nothing(): void
    {
        $this->customer();
        $id = $this->submittedApplication();

        $this->deliver('SUBMISSION_ACK', 'GW-LATE', 'E-1')->assertStatus(500);

        ApplicationSubmission::latest('id')->first()
            ->update(['provider_reference' => 'GW-LATE']);

        $this->artisan('fynn:provider-events:retry')
            ->expectsOutputToContain('Nothing was retried')
            ->assertSuccessful();

        $this->assertSame('failed', ProviderEvent::sole()->processing_status);
        $this->assertSame('started', LoanApplication::find($id)->status);
    }

    // --- What is not retried ------------------------------------------------

    public function test_only_failures_are_re_attempted(): void
    {
        $this->customer();
        $id = $this->submittedApplication();

        $reference = 'GW-1';
        ApplicationSubmission::latest('id')->first()
            ->update(['provider_reference' => $reference]);

        // Processed, then a stale one that was deliberately ignored.
        $this->deliver('APPROVED', $reference, 'E-1')->assertAccepted();
        $this->deliver('IN_REVIEW', $reference, 'E-2')->assertAccepted();

        $this->assertSame('processed', ProviderEvent::where('event_type', 'application_approved')->first()->processing_status);
        $this->assertSame('ignored', ProviderEvent::where('event_type', 'application_under_review')->first()->processing_status);

        $this->artisan('fynn:provider-events:retry --apply')
            ->expectsOutputToContain('No provider events are waiting')
            ->assertSuccessful();

        // Neither was touched, and neither moved the application.
        foreach (ProviderEvent::all() as $record) {
            $this->assertSame(0, $record->retry_count);
        }
        $this->assertSame('approved', LoanApplication::find($id)->status);
    }

    public function test_a_retry_obeys_the_same_transition_rules(): void
    {
        $this->customer();
        $id = $this->submittedApplication();

        // An early event that could not attach.
        $this->deliver('IN_REVIEW', 'GW-LATE', 'E-1')->assertStatus(500);

        // By the time the reference exists, the application has already
        // been approved by a later event.
        $reference = 'GW-LATE';
        ApplicationSubmission::latest('id')->first()
            ->update(['provider_reference' => $reference]);
        $this->deliver('APPROVED', $reference, 'E-2')->assertAccepted();
        $this->assertSame('approved', LoanApplication::find($id)->status);

        $this->artisan('fynn:provider-events:retry --apply')->assertSuccessful();

        // The retry goes through the same policy as a fresh delivery, so a
        // stale event cannot un-approve anything by arriving late twice.
        $this->assertSame('approved', LoanApplication::find($id)->status);

        $retried = ProviderEvent::where('event_type', 'application_under_review')->sole();
        $this->assertSame('ignored', $retried->processing_status);
        $this->assertSame('stale_event', $retried->outcome_reason);
    }

    public function test_a_retry_never_duplicates_the_customers_timeline(): void
    {
        $this->customer();
        $id = $this->submittedApplication();

        $this->deliver('SUBMISSION_ACK', 'GW-LATE', 'E-1')->assertStatus(500);

        ApplicationSubmission::latest('id')->first()
            ->update(['provider_reference' => 'GW-LATE']);

        $this->artisan('fynn:provider-events:retry --apply')->assertSuccessful();
        $this->artisan('fynn:provider-events:retry --apply')->assertSuccessful();

        $received = collect(
            $this->getJson("/api/v1/applications/{$id}")->json('data.events')
        )->filter(fn ($e) => $e['label'] === 'Application received');

        // Applied once. The second run finds it no longer retryable.
        $this->assertCount(1, $received);
        $this->assertSame(1, ProviderEvent::sole()->retry_count);
    }
}
