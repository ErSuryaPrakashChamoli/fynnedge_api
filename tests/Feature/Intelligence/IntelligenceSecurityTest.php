<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Domain\Scan\Fixture\FixtureScenario as ScanScenario;
use App\Domain\Vault\Malware\MalwareScanner;
use App\Domain\Vault\Malware\UnavailableMalwareScanner;
use App\Models\Document;
use App\Models\FinancialMoment;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose intelligence, and what it is allowed to contain.
 */
class IntelligenceSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
    }

    private function customer(string $name = 'Sample Customer'): User
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
        Sanctum::actingAs($user);

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

    // --- IDOR ------------------------------------------------------------------

    public function test_a_customer_never_sees_another_customers_moments(): void
    {
        $theirs = $this->customer('Other Person');
        $this->application();

        $this->assertGreaterThan(
            0,
            FinancialMoment::where('user_id', $theirs->getKey())->count(),
        );

        $mine = $this->customer('Me');

        $timeline = $this->getJson('/api/v1/intelligence/timeline')->assertOk();
        $intelligence = $this->getJson('/api/v1/intelligence')->assertOk();

        $this->assertCount(0, $timeline->json('data'));
        $this->assertCount(0, $intelligence->json('data.recent_moments'));

        // And no trace of the other customer anywhere in either response.
        foreach ([$timeline->getContent(), $intelligence->getContent()] as $body) {
            $this->assertStringNotContainsString('Other Person', $body);
            $this->assertStringNotContainsString('FE-2026', $body);
        }
    }

    public function test_one_customers_observations_never_reach_anothers_state(): void
    {
        $theirs = $this->customer('Other Person');
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 900000, 'monthly_expenses' => 10000,
            'existing_emi' => 0, 'savings' => 9000000,
            'employment_type' => 'salaried',
        ])->assertOk();

        $this->customer('Me');

        // No observations of their own, so no history — regardless of how
        // many somebody else has.
        $this->assertFalse(
            $this->getJson('/api/v1/intelligence')->json('data.history.has_comparable_history')
        );
        $this->assertSame(
            0,
            $this->getJson('/api/v1/intelligence')->json('data.history.observations')
        );
    }

    public function test_intelligence_requires_a_session(): void
    {
        $this->getJson('/api/v1/intelligence')->assertStatus(401);
        $this->getJson('/api/v1/intelligence/timeline')->assertStatus(401);
    }

    // --- Data minimisation -------------------------------------------------------

    public function test_nothing_sensitive_reaches_the_intelligence_layer(): void
    {
        $user = $this->customer();
        config([
            'fynnedge.scan.driver' => 'fixture',
            'fynnedge.kyc.driver' => 'fixture',
            'fynnedge.scan.fixture_scenario' => ScanScenario::Good->value,
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.lender.fixture_scenario' => FixtureScenario::Simulated->value,
        ]);
        $consents = app(ConsentService::class);
        $consents->grant($user, ConsentType::DocumentProcessing, 'privacy_screen');
        $consents->grant($user, ConsentType::IdentityVerification, 'privacy_screen');
        $consents->grant($user, ConsentType::LenderDataSharing, 'application');
        $this->app->instance(
            MalwareScanner::class,
            new UnavailableMalwareScanner,
        );

        // A whole journey: a document, a scan, an application, a submission.
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $documentId = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'income.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$documentId}/scan")->assertOk();

        $applicationId = $this->application();
        $this->postJson("/api/v1/applications/{$applicationId}/submission")->assertOk();

        $document = Document::findOrFail($documentId);

        $body = $this->getJson('/api/v1/intelligence')->getContent()
            .$this->getJson('/api/v1/intelligence/timeline')->getContent();

        foreach ([
            // Identity values Module 25 discards.
            FixtureKycVerificationProvider::ID_NUMBER, 'AAAAA0000A',
            '000000000000', 'aadhaar', 'Aadhaar',

            // Where a file lives, and anything out of it.
            $document->storage_key, 'storage_key', 'income.pdf',
            'net_pay', 'grossPay', 'extraction',

            // Provider and system internals.
            'payload', 'provider_reference', 'idempotency', 'file_digest',
            'evidence', 'rule',
        ] as $leak) {
            $this->assertStringNotContainsString(
                (string) $leak,
                $body,
                "the intelligence layer returned [{$leak}]",
            );
        }
    }

    public function test_a_moments_own_reasoning_stays_server_side(): void
    {
        $this->customer();
        $this->application();

        $moment = FinancialMoment::firstOrFail();

        // FynnEdge can always answer "why did this appear?"...
        $this->assertNotEmpty($moment->rule);

        // ...and the customer is not shown the answer, because it describes
        // the system rather than them.
        $this->assertArrayNotHasKey('rule', $moment->toArray());
        $this->assertArrayNotHasKey('evidence', $moment->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $moment->toArray());
    }

    public function test_nothing_about_a_moment_reaches_the_log(): void
    {
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        $this->customer();
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 275000, 'monthly_expenses' => 40000,
            'existing_emi' => 10000, 'savings' => 600000,
            'employment_type' => 'salaried',
        ])->assertOk();

        $log = implode("\n", $lines);

        // A customer's figures are not operational facts.
        foreach (['275000', 'monthly_income', 'emergency_months'] as $figure) {
            $this->assertStringNotContainsString($figure, $log, "logged: {$figure}");
        }
    }

    // --- Personalisation cannot decide anything ------------------------------------

    public function test_personalisation_changes_nothing_it_must_not(): void
    {
        $user = $this->customer();

        $scoreBefore = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');
        $matchBefore = $this->getJson(
            '/api/v1/fynn-match?amount=1000000&tenure_months=60'
        )->assertOk()->json('data');

        // Read the intelligence layer hard. It is the thing that
        // personalises, so if anything it touches could move eligibility,
        // pricing or a score, doing this would move them.
        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/intelligence')->assertOk();
            $this->getJson('/api/v1/intelligence/timeline')->assertOk();
            $this->getJson('/api/v1/home')->assertOk();
        }

        $this->assertSame(
            $scoreBefore,
            $this->getJson('/api/v1/fynn-score')->assertOk()->json('data'),
            'reading the intelligence layer moved the FynnScore',
        );
        $this->assertSame(
            $matchBefore,
            $this->getJson(
                '/api/v1/fynn-match?amount=1000000&tenure_months=60'
            )->assertOk()->json('data'),
            'reading the intelligence layer changed what the customer is eligible for',
        );

        // And it wrote nothing. Reading is not an event.
        $this->assertSame(0, FinancialMoment::where('user_id', $user->getKey())->count());
        $this->assertSame(0, $user->financialObservations()->count());
    }

    public function test_the_next_action_never_contradicts_a_blocked_application(): void
    {
        config([
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.lender.fixture_scenario' => FixtureScenario::TimeoutAfterSend->value,
        ]);

        $user = $this->customer();
        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');

        $applicationId = $this->application();
        $this->postJson("/api/v1/applications/{$applicationId}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'unknown');

        /*
         * This customer has been told, in as many words, not to submit
         * again and that support can check what happened. Home used to
         * answer "Set your first goal" — the product contradicting itself
         * about the most consequential thing it had said to them.
         */
        $intelligence = $this->getJson('/api/v1/intelligence')->assertOk()->json('data');

        $this->assertSame('resolve_submission_status', $intelligence['next_action']['type']);
        $this->assertSame(
            'application_attention',
            $intelligence['insights'][0]['type'],
        );

        // Home agrees, because Home asks the same rules.
        $this->assertSame(
            'resolve_submission_status',
            $this->getJson('/api/v1/home')->assertOk()->json('data.primary_action.key'),
        );

        // And nothing anywhere suggests trying again or going elsewhere.
        $whole = strtolower(json_encode($intelligence));
        foreach (['apply again', 'submit again', 'try another', 'explore'] as $contradiction) {
            $this->assertStringNotContainsString($contradiction, $whole);
        }
    }

    public function test_the_ladder_puts_a_missing_profile_above_everything_ordinary(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer']);
        Sanctum::actingAs($user);

        $this->assertSame(
            'complete_profile',
            $this->getJson('/api/v1/intelligence')->json('data.next_action.type'),
        );
    }
}
