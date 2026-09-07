<?php

namespace Tests\Feature\Vault;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Models\ApplicationSubmission;
use App\Models\DocumentScan;
use App\Models\ProviderEvent;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What needs a person, and how they would ever find out.
 *
 * Every boundary since Module 23 records its failures for "an operator".
 * Until this command there was no way for that person to find any of them,
 * which made the honesty operationally useless — a customer told to contact
 * support could not have been found by support.
 */
class HealthTest extends TestCase
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

    /** An application whose submission nobody could confirm. */
    private function unconfirmedSubmission(): string
    {
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        config([
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'unknown');

        return $id;
    }

    /** @return array<string, mixed> */
    private function healthReport(): array
    {
        // Called rather than asserted: $this->artisan() is lazy and only
        // runs when an expectation resolves it, so the output would be
        // empty here.
        Artisan::call('fynn:health', ['--json' => true]);

        return json_decode(Artisan::output(), true) ?? [];
    }

    // --- Quiet when there is nothing to say ---------------------------------

    public function test_a_healthy_system_says_so_and_exits_clean(): void
    {
        $this->artisan('fynn:health')
            ->expectsOutputToContain('Nothing is waiting on a person')
            ->assertExitCode(0);

        $report = $this->healthReport();
        $this->assertTrue($report['healthy']);
        $this->assertSame(0, $report['blocking_count']);
        $this->assertSame([], $report['concerns']);
    }

    public function test_it_reports_only_what_is_actually_stuck(): void
    {
        // A perfectly ordinary application that reached nobody, because no
        // provider is connected. Not stuck, and not reported.
        $this->customer();

        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated();

        $this->assertTrue($this->healthReport()['healthy']);
    }

    // --- The one blocking state ---------------------------------------------

    public function test_an_unconfirmed_submission_is_blocking(): void
    {
        $this->customer();
        $this->unconfirmedSubmission();

        // The customer has been told not to send it again and to contact
        // support. Only a person can resolve that, so the exit code says so
        // and whatever runs this can act on it.
        $this->artisan('fynn:health')->assertExitCode(1);

        $report = $this->healthReport();
        $this->assertFalse($report['healthy']);
        $this->assertSame(1, $report['blocking_count']);

        $concern = collect($report['concerns'])
            ->firstWhere('label', 'Submissions never confirmed');

        $this->assertSame('blocking', $concern['severity']);
        $this->assertSame(1, $concern['count']);
        $this->assertStringContainsString('contact support', $concern['why']);
        $this->assertNotNull($concern['oldest_at']);
    }

    public function test_a_resolved_submission_stops_being_reported(): void
    {
        $this->customer();
        $this->unconfirmedSubmission();

        $this->assertFalse($this->healthReport()['healthy']);

        // A provider event confirms receipt, the way Module 28 resolves it.
        ApplicationSubmission::latest('id')->first()
            ->update(['outcome' => 'provider_received']);

        $this->assertTrue($this->healthReport()['healthy']);
    }

    // --- Attention, but nobody is blocked ------------------------------------

    public function test_failed_provider_events_are_attention_not_blocking(): void
    {
        ProviderEvent::create([
            'provider_code' => 'fixture',
            'idempotency_key' => 'fixture:E-1',
            'event_type' => 'submission_received',
            'provider_reference' => 'GW-NOWHERE',
            'is_simulated' => true,
            'processing_status' => 'failed',
            'outcome_reason' => 'unresolved_reference',
            'occurred_at' => Carbon::now()->subHour(),
            'received_at' => Carbon::now()->subHour(),
        ]);

        // Worth looking at; nobody is stuck, and there is a command that
        // may fix it — so the exit code stays clean.
        $this->artisan('fynn:health')->assertExitCode(0);

        $concern = collect($this->healthReport()['concerns'])
            ->firstWhere('label', 'Provider events not applied');

        $this->assertSame('attention', $concern['severity']);
        $this->assertStringContainsString('fynn:provider-events:retry', $concern['why']);
    }

    public function test_a_safety_check_failure_is_reported_apart_from_an_outage(): void
    {
        $user = $this->customer();

        foreach ([
            'document_failed_safety_check',
            'provider_error',
        ] as $reason) {
            DocumentScan::create([
                'user_id' => $user->getKey(),
                'document_id' => $this->storedDocument(),
                'provider' => 'fixture',
                'is_sample' => true,
                'status' => 'failed',
                'family' => 'income',
                'schema_version' => 'income_v1',
                'file_digest' => str_repeat('a', 64),
                'failure_reason' => $reason,
            ]);
        }

        $concerns = collect($this->healthReport()['concerns']);

        /*
         * Two different things, reported as two different things.
         *
         * A file that failed a safety check is a security signal; a
         * provider timeout is an outage. Counting them together would let
         * one hide inside the other.
         */
        $this->assertSame(
            1,
            $concerns->firstWhere('label', 'Documents that failed a safety check')['count'],
        );
        $this->assertSame(
            1,
            $concerns->firstWhere('label', 'Document readings that failed')['count'],
        );
    }

    public function test_several_concerns_are_reported_together(): void
    {
        $this->customer();
        $this->unconfirmedSubmission();

        ProviderEvent::create([
            'provider_code' => 'fixture',
            'idempotency_key' => 'fixture:E-2',
            'event_type' => 'submission_received',
            'provider_reference' => 'GW-NOWHERE',
            'is_simulated' => true,
            'processing_status' => 'failed',
            'occurred_at' => Carbon::now(),
            'received_at' => Carbon::now(),
        ]);

        $report = $this->healthReport();

        $this->assertCount(2, $report['concerns']);

        // One blocking among several still means the exit code is set: the
        // customer is what matters, not the total.
        $this->assertSame(1, $report['blocking_count']);
        $this->artisan('fynn:health')->assertExitCode(1);
    }

    // --- What it must not expose ---------------------------------------------

    public function test_it_reports_counts_and_never_customer_data(): void
    {
        $this->customer();
        $this->unconfirmedSubmission();

        Artisan::call('fynn:health', ['--json' => true]);
        $output = Artisan::output();

        /*
         * An operational report is read in places a customer record should
         * not travel to — a terminal, a log aggregator, a monitoring check.
         * It carries counts and ages, and nothing that identifies anybody.
         */
        foreach ([
            'Sample Customer', '9876543210', 'FE-2026', 'Pune',
            '150000', 'idempotency', 'GW-',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $output, $leak);
        }
    }

    private function storedDocument(): int
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return (int) $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'p.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');
    }
}
