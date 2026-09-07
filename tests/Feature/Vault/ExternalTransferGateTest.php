<?php

namespace Tests\Feature\Vault;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Kyc\KycVerificationProvider;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureDocumentUnderstandingProvider;
use App\Domain\Vault\Malware\MalwareScanner;
use App\Domain\Vault\Malware\ScanVerdict;
use App\Domain\Vault\Malware\UnavailableMalwareScanner;
use App\Models\Document;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use App\Services\Vault\ExternalTransferGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * No customer file leaves FynnEdge unless something has checked it.
 *
 * NO MALWARE SCANNER IS CONNECTED, and no real document provider is either.
 * These tests exist because that combination is safe only by accident today
 * — nothing actually leaves — and would stop being safe the moment somebody
 * writes a real adapter. The gate is what makes it safe on purpose.
 */
class ExternalTransferGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
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

        return $user;
    }

    private function upload(string $type): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, "{$type}.pdf", 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    /** A provider that says it is real, so the gate applies. */
    private function realScanProvider(): void
    {
        $this->app->instance(
            DocumentUnderstandingProvider::class,
            new class extends FixtureDocumentUnderstandingProvider
            {
                public int $calls = 0;

                public function isSample(): bool
                {
                    // Not simulated: the bytes would genuinely leave.
                    return false;
                }

                public function process(
                    Document $document,
                    string $bytes,
                    $family,
                ): array {
                    $this->calls++;

                    return parent::process($document, $bytes, $family);
                }
            },
        );
    }

    private function realKycProvider(): void
    {
        $this->app->instance(
            KycVerificationProvider::class,
            new class extends FixtureKycVerificationProvider
            {
                public int $calls = 0;

                public function isSample(): bool
                {
                    return false;
                }

                public function verify(
                    Document $document,
                    string $bytes,
                    $type,
                ): array {
                    $this->calls++;

                    return parent::verify($document, $bytes, $type);
                }
            },
        );
    }

    private function scanner(ScanVerdict $verdict): void
    {
        $this->app->instance(
            MalwareScanner::class,
            new class($verdict) implements MalwareScanner
            {
                public int $calls = 0;

                public function __construct(private readonly ScanVerdict $verdict) {}

                public function isAvailable(): bool
                {
                    return true;
                }

                public function name(): ?string
                {
                    return 'test-scanner';
                }

                public function scan(Document $document, string $bytes): ScanVerdict
                {
                    $this->calls++;

                    return $this->verdict;
                }
            },
        );
    }

    private function allowScanning(User $user): void
    {
        config(['fynnedge.scan.driver' => 'fixture']);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );
    }

    // --- What is true today --------------------------------------------------

    public function test_nothing_scans_documents_and_the_default_says_so(): void
    {
        $scanner = app(MalwareScanner::class);

        $this->assertInstanceOf(UnavailableMalwareScanner::class, $scanner);
        $this->assertFalse($scanner->isAvailable());

        // Not clean. "Nobody looked" must never become "we looked and it
        // was fine" anywhere downstream.
        $this->assertSame(
            ScanVerdict::NotScanned,
            $scanner->scan(new Document, 'anything'),
        );
        $this->assertFalse(ScanVerdict::NotScanned->permitsExternalTransfer());
        $this->assertFalse(ScanVerdict::Inconclusive->permitsExternalTransfer());
        $this->assertFalse(ScanVerdict::Infected->permitsExternalTransfer());
        $this->assertTrue(ScanVerdict::Clean->permitsExternalTransfer());
    }

    // --- The gate ------------------------------------------------------------

    public function test_a_real_provider_cannot_receive_an_unscanned_document(): void
    {
        $user = $this->customer();
        $this->allowScanning($user);
        $this->realScanProvider();

        $id = $this->upload('income');

        $response = $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertStatus(409);

        $response->assertJsonPath('reason', 'malware_scanning_not_configured');

        // The provider was never called, and no attempt was recorded.
        $this->assertSame(0, app(DocumentUnderstandingProvider::class)->calls);
        $this->assertDatabaseCount('document_scans', 0);

        // The customer is told the truth: this is about FynnEdge, not about
        // their file.
        $this->assertStringContainsString(
            'cannot send documents to an external service yet',
            $response->json('message'),
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'threat',
            $response->json('message'),
        );
    }

    public function test_a_real_kyc_provider_cannot_receive_an_unscanned_document(): void
    {
        $user = $this->customer();
        config(['fynnedge.kyc.driver' => 'fixture']);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );

        $this->realKycProvider();
        $id = $this->upload('identity');

        // An identity document is the last file that should reach an
        // unvetted external service.
        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'malware_scanning_not_configured');

        $this->assertSame(0, app(KycVerificationProvider::class)->calls);
        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    public function test_an_infected_document_is_not_sent_and_is_named_as_such(): void
    {
        $user = $this->customer();
        $this->allowScanning($user);
        $this->realScanProvider();
        $this->scanner(ScanVerdict::Infected);

        $id = $this->upload('income');

        /*
         * The request succeeds in accepting the work; the refusal happens
         * in the worker that would have sent the file, and shows up on the
         * scan. Under a real queue an immediate refusal is not possible, so
         * this is the honest shape.
         */
        $data = $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->json('data');

        $this->assertSame('failed', $data['status']);
        $this->assertSame('document_failed_safety_check', $data['reason']);
        $this->assertNull($data['extraction']);

        // The provider never saw it.
        $this->assertSame(0, app(DocumentUnderstandingProvider::class)->calls);

        // A finding about the file, said plainly and without accusation.
        $this->assertStringContainsString(
            'did not pass a safety check',
            $data['message'],
        );
    }

    public function test_an_inconclusive_check_is_not_treated_as_clean(): void
    {
        $user = $this->customer();
        $this->allowScanning($user);
        $this->realScanProvider();
        $this->scanner(ScanVerdict::Inconclusive);

        $id = $this->upload('income');

        // The absence of a finding is not a finding.
        $data = $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->json('data');

        $this->assertSame('failed', $data['status']);
        $this->assertSame('document_check_inconclusive', $data['reason']);
        $this->assertSame(0, app(DocumentUnderstandingProvider::class)->calls);
    }

    public function test_a_clean_document_reaches_a_real_provider(): void
    {
        $user = $this->customer();
        $this->allowScanning($user);
        $this->realScanProvider();
        $this->scanner(ScanVerdict::Clean);

        $id = $this->upload('income');

        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        // The gate is a gate, not a wall: a checked file goes through.
        $this->assertSame(1, app(DocumentUnderstandingProvider::class)->calls);
        $this->assertSame(1, app(MalwareScanner::class)->calls);
        $this->assertDatabaseCount('document_scans', 1);
    }

    // --- Simulated destinations ---------------------------------------------

    public function test_a_simulated_provider_needs_no_scanner(): void
    {
        $user = $this->customer();
        $this->allowScanning($user);

        $id = $this->upload('income');

        // The bytes never leave this process, so requiring a scanner would
        // block development while protecting nobody — and teach people to
        // route around the gate.
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->assertDatabaseCount('document_scans', 1);
    }

    public function test_a_simulated_transfer_is_never_recorded_as_clean(): void
    {
        $verdict = app(ExternalTransferGate::class)->assertMayLeave(
            new Document,
            'bytes',
            destinationIsSimulated: true,
            purpose: 'test',
        );

        // A simulated transfer is not evidence a file is safe, and a later
        // real transfer must not be able to lean on it.
        $this->assertSame(ScanVerdict::NotScanned, $verdict);
        $this->assertFalse($verdict->permitsExternalTransfer());
    }

    public function test_nothing_in_a_request_can_choose_the_destination_kind(): void
    {
        $user = $this->customer();
        $this->allowScanning($user);
        $this->realScanProvider();

        $id = $this->upload('income');

        // Whether a destination is simulated is the provider's own answer,
        // and no request may supply it.
        foreach ([
            ['is_sample' => true],
            ['is_simulated' => true],
            ['skip_scan' => true],
            ['destination_is_simulated' => true],
            ['force' => true],
        ] as $payload) {
            $this->postJson("/api/v1/documents/{$id}/scan", $payload)
                ->assertStatus(409)
                ->assertJsonPath('reason', 'malware_scanning_not_configured');
        }

        $this->assertSame(0, app(DocumentUnderstandingProvider::class)->calls);
        $this->assertDatabaseCount('document_scans', 0);
    }
}
