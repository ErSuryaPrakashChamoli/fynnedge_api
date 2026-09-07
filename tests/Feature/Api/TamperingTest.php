<?php

namespace Tests\Feature\Api;

use App\Domain\Consent\ConsentSource;
use App\Domain\Consent\ConsentType;
use App\Models\Consent;
use App\Models\Document;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a customer sends, and what the server is willing to believe.
 *
 * Every authoritative figure in FynnEdge is produced by an engine from the
 * customer's own saved profile. This tries to forge each one from the client
 * and asserts the server ignored it — because a score, an EMI or a match a
 * customer can set is a number that means nothing.
 */
class TamperingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);

        $this->user = User::factory()->create();
        $this->user->ensureProfiles();
        $this->user->customerProfile->update(['full_name' => 'Real Customer']);
        $this->user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 0,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($this->user);
    }

    // --- Financial profile ---------------------------------------------

    public function test_derived_figures_sent_by_the_client_are_ignored(): void
    {
        $response = $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 0,
            'savings' => 600000,
            'employment_type' => 'salaried',

            // Everything the engine owns, offered by the client.
            'derived' => [
                'surplus' => 999999,
                'emi_ratio' => 1,
                'expense_ratio' => 1,
                'monthly_outgo' => 0,
                'emergency_months' => 999,
            ],
            'surplus' => 999999,
            'emi_ratio' => 1,
            'emergency_months' => 999,
        ])->assertOk()->json('data');

        // income - (expenses + emi + obligations) = 40,000. The engine's.
        $this->assertEquals(40000, $response['derived']['surplus']);
        $this->assertEquals(20, $response['derived']['emi_ratio']);
        $this->assertEquals(10, $response['derived']['emergency_months']);
    }

    public function test_a_forged_score_never_becomes_the_customers_score(): void
    {
        // Stretched figures, so a perfect score would be unmistakably forged.
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 60000,
            'monthly_expenses' => 40000,
            'existing_emi' => 25000,
            'other_obligations' => 0,
            'savings' => 20000,
            'employment_type' => 'salaried',
            'score' => 100,
            'fynn_score' => 100,
            'band' => 'strong',
        ])->assertOk();

        $honest = $this->getJson('/api/v1/fynn-score')->json('data');

        // The score the three dimensions actually produce for these figures.
        $this->assertLessThan(50, $honest['score']);
        $this->assertNotSame('strong', $honest['band']);
        $this->assertSame(
            $honest['score'],
            $this->getJson('/api/v1/home')->json('data.score.score'),
        );
    }

    // --- Applications ---------------------------------------------------

    public function test_pricing_sent_by_the_client_is_replaced_by_the_engines(): void
    {
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,

            // A cheaper loan than the catalogue offers.
            'interest_rate' => 0.1,
            'emi' => 1,
            'total_interest' => 0,
            'total_payable' => 1000000,
            'processing_fee' => 0,
            'projected_emi_ratio' => 1,
            'match_category' => 'strong_match',
            'lender' => 'Bank Of Nowhere',
            'product_name' => 'Free Money',
        ])->assertCreated()->json('data');

        $product = $this->getJson('/api/v1/products/prod_bl_b')->json('data');

        $this->assertSame($product['interest_rate'], $application['interest_rate']);
        $this->assertSame($product['lender'], $application['lender']);
        $this->assertSame($product['name'], $application['product_name']);
        $this->assertGreaterThan(1, $application['emi']);
        $this->assertGreaterThan(0, $application['processing_fee']);
        $this->assertNotSame('Bank Of Nowhere', $application['lender']);
    }

    public function test_a_client_cannot_start_an_application_already_approved(): void
    {
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,

            // Every field that would speak for a provider.
            'status' => 'approved',
            'is_simulated' => false,
            'gateway' => 'united-credit-bank',
            'provider_reference' => 'UCB-REAL-0001',
            'submitted_at' => now()->toIso8601String(),
            'acknowledged_at' => now()->toIso8601String(),
            'reference' => 'FE-2020-000001',
        ])->assertCreated()->json('data');

        $this->assertSame('started', $application['status']);
        $this->assertNull($application['submitted_at']);
        $this->assertNull($application['provider_reference']);
        $this->assertStringStartsWith('FE-', $application['reference']);
        $this->assertNotSame('FE-2020-000001', $application['reference']);

        $row = LoanApplication::find($application['id']);
        $this->assertNull($row->gateway);
        $this->assertNull($row->acknowledged_at);
    }

    public function test_an_update_cannot_promote_a_draft_to_a_decision(): void
    {
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data.id');

        $this->patchJson("/api/v1/applications/{$id}", [
            'amount' => 900000,
            'tenure_months' => 48,
            'status' => 'approved',
            'is_simulated' => false,
            'provider_reference' => 'UCB-REAL-0002',
            'emi' => 1,
            'match_category' => 'strong_match',
        ])->assertOk();

        $row = LoanApplication::find($id);

        $this->assertSame('started', $row->status);
        $this->assertNull($row->provider_reference);
        $this->assertGreaterThan(1, (float) $row->emi);
        // Re-priced by the engine at the new figures, not set by the client.
        $this->assertSame(900000.0, (float) $row->amount);
    }

    #[DataProvider('impossibleTransitions')]
    public function test_impossible_state_transitions_cannot_be_manufactured(
        string $from,
        string $to,
    ): void {
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data.id');

        if ($from === 'submitted') {
            $this->postJson("/api/v1/applications/{$id}/submit", [
                'acknowledged' => true,
            ])->assertOk();
        }

        if ($from === 'cancelled') {
            $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();
        }

        // Every route a client could reach for, with the target state in it.
        $this->patchJson("/api/v1/applications/{$id}", ['status' => $to]);
        $this->putJson("/api/v1/applications/{$id}", ['status' => $to]);
        $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
            'status' => $to,
        ]);
        $this->postJson("/api/v1/applications/{$id}/cancel", ['status' => $to]);

        $reached = LoanApplication::find($id)->status;

        $this->assertNotSame(
            $to,
            $reached,
            "An application was talked into {$to} from {$from}",
        );

        // And it is still somewhere a customer can actually put it: the
        // three provider states are unreachable without a provider.
        $this->assertContains(
            $reached,
            ['started', 'submitted', 'cancelled'],
            "An application reached the provider state {$reached}",
        );
    }

    public static function impossibleTransitions(): array
    {
        return [
            'started to approved' => ['started', 'approved'],
            'started to disbursed' => ['started', 'disbursed'],
            'started to under review' => ['started', 'under_review'],
            'started to declined' => ['started', 'declined'],
            'submitted to approved' => ['submitted', 'approved'],
            'submitted to disbursed' => ['submitted', 'disbursed'],
            'cancelled to approved' => ['cancelled', 'approved'],
            'cancelled to submitted' => ['cancelled', 'submitted'],
        ];
    }

    public function test_submitting_never_produces_a_provider_decision(): void
    {
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data.id');

        $data = $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk()->json('data');

        $this->assertSame('submitted', $data['status']);
        $this->assertTrue($data['is_simulated']);

        // No event claims a provider did anything.
        foreach ($data['events'] as $event) {
            $this->assertNotSame('provider', $event['source']);
        }
    }

    // --- Documents ------------------------------------------------------

    public function test_a_client_cannot_choose_where_its_file_is_stored(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $id = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
            'storage_disk' => 'public',
            'storage_key' => '../../public/leak.pdf',
            'status' => 'verified',
            'mime_type' => 'application/x-php',
            'size_bytes' => 1,
        ])->assertCreated()->json('data.id');

        $document = Document::find($id);

        $this->assertSame(DocumentVaultService::DISK, $document->storage_disk);
        $this->assertStringNotContainsString('..', $document->storage_key);
        $this->assertStringNotContainsString('public', $document->storage_key);
        $this->assertSame('uploaded', $document->status);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(1, $document->size_bytes);
    }

    public function test_a_document_response_never_carries_its_storage_address(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated();

        $body = $this->getJson('/api/v1/documents')->assertOk()->getContent();

        foreach ([
            'storage_key', 'storage_disk', 'vault/', storage_path(), '/app/',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
    }

    // --- Consent ---------------------------------------------------------

    public function test_a_client_cannot_choose_a_consent_version_or_state(): void
    {
        $granted = $this->postJson('/api/v1/consents', [
            'type' => 'ai_memory',
            'granted' => true,
            'version' => 'v999',
            'status' => 'granted',
            'granted_at' => '2020-01-01T00:00:00+00:00',
        ])->assertOk()->json('data');

        $row = Consent::where('user_id', $this->user->id)
            ->where('type', 'ai_memory')
            ->latest('id')
            ->first();

        // The version is the catalogue's, and the timestamp is the server's.
        $this->assertNotSame('v999', $row->version);
        $this->assertTrue($row->granted_at->isAfter(now()->subMinutes(5)));
        $this->assertSame('granted', $row->status);
        $this->assertIsString($granted['consents'][0]['version'] ?? '');
    }

    public function test_a_forged_consent_provenance_is_refused(): void
    {
        // `source` is part of the record of how permission was obtained.
        // Free text there would let a caller assert a provenance — a paper
        // form, a phone call — for a decision that happened in the app.
        $this->postJson('/api/v1/consents', [
            'type' => 'ai_memory',
            'source' => 'signed_paper_form',
        ])->assertStatus(422);

        $this->postJson('/api/v1/consents', ['type' => 'ai_memory'])
            ->assertOk();

        // Withdrawal has no FormRequest, so it narrows the value itself.
        $this->postJson('/api/v1/consents/ai_memory/withdraw', [
            'source' => 'signed_paper_form',
        ])->assertOk();

        foreach (Consent::where('user_id', $this->user->id)->get() as $consent) {
            $this->assertContains(
                $consent->source,
                ConsentSource::ids(),
                "A consent was recorded with source '{$consent->source}'",
            );
        }
    }

    public function test_a_withheld_capability_cannot_be_granted(): void
    {
        // Module 10: FynnEdge cannot perform these, so it does not accept
        // permission to. Granting one would be a promise it cannot keep.
        foreach ([
            'credit_bureau_check',
            'lender_data_sharing',
            'ai_training',
            'marketing_messages',
        ] as $withheld) {
            $this->postJson('/api/v1/consents', [
                'type' => $withheld,
                'granted' => true,
            ])->assertStatus(422);

            $this->assertNull(
                Consent::where('user_id', $this->user->id)
                    ->where('type', $withheld)
                    ->where('status', 'granted')
                    ->first(),
                "{$withheld} was recorded as granted",
            );
        }
    }

    /**
     * A submission result is what a provider reported. Nothing a customer
     * sends may manufacture provider receipt, and nothing may relabel a
     * simulated submission as a real one.
     */
    public function test_provider_acknowledgement_cannot_be_forged(): void
    {
        config(['fynnedge.lender.driver' => 'fixture']);

        app(ConsentService::class)->grant(
            $this->user,
            ConsentType::LenderDataSharing,
            'application_review',
        );

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $forged = $this->postJson("/api/v1/applications/{$application}/submission", [
            'outcome' => 'provider_received',
            'provider_received' => true,
            'status' => 'approved',
            'is_simulated' => false,
            'provider' => 'real-provider',
            'provider_reference' => 'FORGED-0001',
        ])->assertOk();

        $forged->assertJsonPath('data.submission.outcome', 'simulated')
            ->assertJsonPath('data.submission.provider_received', false)
            ->assertJsonPath('data.submission.is_simulated', true)
            ->assertJsonPath('data.submission.provider_reference', null);

        // And the application's own status never became a provider decision.
        $this->assertSame(
            'started',
            LoanApplication::find($application)->status,
        );
    }

    /**
     * A verification is what a service established. Nothing a customer
     * sends may become one, and nothing they send may relabel a synthetic
     * result as a real one.
     */
    public function test_a_verification_cannot_be_supplied_by_the_customer(): void
    {
        Storage::fake(DocumentVaultService::DISK);
        config([
            'fynnedge.kyc.driver' => 'fixture',
            'fynnedge.kyc.fixture_scenario' => 'name_mismatch',
        ]);

        $this->user->customerProfile->update(['full_name' => 'Sample Customer']);

        app(ConsentService::class)->grant(
            $this->user,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $document = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'id.pdf', 'application/pdf', null, true),
            'type' => 'identity',
        ])->assertCreated()->json('data.id');

        $forged = $this->postJson("/api/v1/documents/{$document}/kyc", [
            'status' => 'verified',
            'document_result' => 'passed',
            'identity_result' => 'passed',
            'match' => true,
            'provider' => 'real-provider',
            'is_sample' => false,
        ])->assertOk();

        // The service's answer, labelled as the sample it is.
        $forged->assertJsonPath('data.provider', 'fixture')
            ->assertJsonPath('data.is_sample', true)
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.identity_result', 'failed');

        // And no forged claim reached the profile.
        $this->assertSame(
            'Sample Customer',
            $this->user->customerProfile->fresh()->full_name,
        );
    }

    /**
     * An extraction is what a provider reported. Nothing a customer sends
     * may become one, and nothing they send may relabel a sample reading as
     * a real one.
     */
    public function test_an_extraction_cannot_be_supplied_by_the_customer(): void
    {
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.scan.driver' => 'fixture']);

        app(ConsentService::class)->grant(
            $this->user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $document = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'p.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $forged = $this->postJson("/api/v1/documents/{$document}/scan", [
            'status' => 'completed',
            'provider' => 'real-provider',
            'is_sample' => false,
            'confidence' => 1,
            'schema_version' => 'income_v99',
            'extraction' => [
                ['key' => 'net_income', 'value' => '900000', 'confidence' => 'high'],
            ],
        ])->assertOk();

        // The provider's answer, labelled as the sample it is.
        $forged->assertJsonPath('data.provider', 'fixture')
            ->assertJsonPath('data.is_sample', true)
            ->assertJsonPath('data.schema_version', 'income_v1');

        $net = collect($forged->json('data.extraction'))
            ->firstWhere('key', 'net_income');
        $this->assertSame(75000, $net['amount']);

        // And no forged figure reached the profile.
        $this->assertNotSame(
            '900000.00',
            (string) $this->user->financialProfile->fresh()->monthly_income,
        );
    }

    /**
     * A credit score is a fact obtained from a third party. Nothing a
     * customer sends may become one.
     */
    public function test_a_credit_score_cannot_be_supplied_by_the_customer(): void
    {
        config(['fynnedge.credit.driver' => 'fixture']);

        app(ConsentService::class)->grant(
            $this->user,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );

        $forged = $this->postJson('/api/v1/credit/check', [
            'score' => 900,
            'score_model' => 'FORGED-V1',
            'scale' => ['min' => 0, 'max' => 900],
            'bureau' => 'cibil',
            'is_sample' => false,
        ])->assertOk();

        // The fixture's answer, not the customer's.
        $forged->assertJsonPath('data.profile.score', 742)
            ->assertJsonPath('data.profile.score_model', 'SAMPLE-SCORE-V1')
            ->assertJsonPath('data.profile.bureau', 'fixture')
            ->assertJsonPath('data.profile.is_sample', true);

        $stored = $this->user->creditReports()->latest('obtained_at')->first();
        $this->assertSame(742, $stored->score);
        $this->assertSame('SAMPLE-SCORE-V1', $stored->score_model);
    }

    /**
     * A credit requirement a customer does not meet cannot be argued away
     * from the request.
     */
    public function test_a_credit_criterion_cannot_be_forged_through_the_query(): void
    {
        config(['fynnedge.credit.driver' => 'fixture']);

        $response = $this->getJson('/api/v1/fynn-match?'.http_build_query([
            'amount' => 500000,
            'tenure_months' => 48,
            'purpose' => 'personal',
            'credit_score' => 900,
            'score' => 900,
            'min_credit_score' => 0,
        ]))->assertOk();

        // No report exists, so no forged score reached the evaluation.
        $this->assertStringNotContainsString('900', $response->json('data.disclaimer') ?? '');
        $this->assertSame(0, $this->user->creditReports()->count());
    }
}
