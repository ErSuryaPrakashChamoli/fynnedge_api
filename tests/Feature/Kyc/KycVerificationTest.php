<?php

namespace Tests\Feature\Kyc;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Kyc\Fixture\FixtureScenario;
use App\Domain\Kyc\KycVerificationProvider;
use App\Models\KycVerification;
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
 * Identity verification: what a service established, and what that is not.
 *
 * NO LIVE KYC PROVIDER IS CONNECTED. Everything here runs against a
 * fictional one. No real person, real PAN, real Aadhaar number, real date of
 * birth or real address is involved anywhere in this suite.
 */
class KycVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.kyc.driver' => 'fixture']);
    }

    private function scenario(FixtureScenario $scenario): void
    {
        config(['fynnedge.kyc.fixture_scenario' => $scenario->value]);
        $this->app->forgetInstance(KycVerificationProvider::class);
    }

    private function customer(?string $name = null): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update([
            'full_name' => $name ?? FixtureKycVerificationProvider::HOLDER_NAME,
            'city' => 'Pune',
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function consent(User $user): void
    {
        app(ConsentService::class)->grant(
            $user,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );
    }

    private function stored(string $type = 'identity'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'id.pdf', 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed>|null */
    private function match(array $data, string $field): ?array
    {
        foreach ($data['field_matches'] ?? [] as $match) {
            if ($match['field'] === $field) {
                return $match;
            }
        }

        return null;
    }

    // --- Consent is its own question ----------------------------------------

    public function test_permission_to_read_a_document_is_not_permission_to_check_an_identity(): void
    {
        $user = $this->customer();
        config(['fynnedge.scan.driver' => 'fixture']);

        // The Module 24 consent, granted in full.
        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        $id = $this->stored();

        // It authorises nothing here. Reading a document and having an
        // identity checked are different questions.
        $this->getJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.status', 'consent_required');

        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertForbidden()
            ->assertJsonPath('reason', 'consent_required');

        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    public function test_without_consent_no_provider_is_asked(): void
    {
        $user = $this->customer();
        $id = $this->stored();

        $spy = new class extends FixtureKycVerificationProvider
        {
            public int $calls = 0;

            public function verify($document, string $bytes, $type): array
            {
                $this->calls++;

                return parent::verify($document, $bytes, $type);
            }
        };

        $this->app->instance(KycVerificationProvider::class, $spy);

        $this->postJson("/api/v1/documents/{$id}/kyc")->assertForbidden();

        $this->assertSame(
            0,
            $spy->calls,
            'a document was sent for verification without consent',
        );
        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    public function test_withdrawing_consent_removes_every_result(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->stored();

        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();
        $this->assertSame(1, $user->kycVerifications()->count());

        $this->postJson('/api/v1/consents/identity_verification/withdraw')
            ->assertSuccessful();

        $this->assertSame(0, $user->kycVerifications()->count());
        $this->assertDatabaseCount('kyc_field_matches', 0);

        // The ledger keeps both decisions.
        $entries = array_values(array_filter(
            $this->getJson('/api/v1/consents/history')->json('data'),
            fn ($r) => $r['type'] === 'identity_verification',
        ));
        $this->assertCount(2, $entries);
    }

    public function test_the_consent_is_not_offered_without_a_provider(): void
    {
        config(['fynnedge.kyc.driver' => 'none']);
        $this->customer();

        $consents = collect($this->getJson('/api/v1/consents')->json('data.consents'));
        $identity = $consents->firstWhere('type', 'identity_verification');

        $this->assertFalse($identity['is_available']);
        $this->assertNull($identity['current_version']);
        $this->assertNotNull($identity['unavailable_reason']);

        $this->postJson('/api/v1/consents', [
            'type' => 'identity_verification',
        ])->assertStatus(422);
    }

    // --- Unavailable is not "not verified" ----------------------------------

    public function test_no_provider_says_unavailable_and_never_not_verified(): void
    {
        config(['fynnedge.kyc.driver' => 'none']);
        $this->customer();
        $id = $this->stored();

        $state = $this->getJson("/api/v1/documents/{$id}/kyc")->assertOk();
        $state->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.reason', 'verification_not_configured')
            ->assertJsonPath('data.field_matches', null);

        $body = $state->getContent();
        $this->assertStringContainsString('not available yet', $body);

        // The one sentence that must never appear when nothing was checked.
        foreach (['could not be verified', 'not verified', 'failed'] as $accusation) {
            $this->assertStringNotContainsStringIgnoringCase($accusation, $body);
        }

        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertStatus(501)
            ->assertJsonPath('reason', 'verification_not_configured');

        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    // --- Every way the provider can behave ----------------------------------

    #[DataProvider('scenarios')]
    public function test_every_provider_behaviour_produces_an_honest_state(
        FixtureScenario $scenario,
        string $expectedStatus,
        ?string $documentResult,
        ?string $identityResult,
    ): void {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario($scenario);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/kyc")->json('data');

        $this->assertSame($expectedStatus, $data['status'], $scenario->value);
        $this->assertSame($documentResult, $data['document_result'], $scenario->value);
        $this->assertSame($identityResult, $data['identity_result'], $scenario->value);

        // Whatever happened, the customer's profile is untouched.
        $this->assertSame(
            FixtureKycVerificationProvider::HOLDER_NAME,
            $user->customerProfile->fresh()->full_name,
        );
    }

    public static function scenarios(): array
    {
        return [
            'document passes and the identity matches' => [
                FixtureScenario::Verified, 'verified', 'passed', 'passed',
            ],
            'document passes and the name differs' => [
                FixtureScenario::NameMismatch, 'needs_review', 'passed', 'failed',
            ],
            'the document did not pass its own checks' => [
                FixtureScenario::DocumentNotVerified, 'not_verified', 'failed', 'not_performed',
            ],
            'the provider reports tampering signals' => [
                FixtureScenario::DocumentTampered, 'not_verified', 'failed', 'not_performed',
            ],
            'the provider could not reach an answer' => [
                FixtureScenario::Ambiguous, 'needs_review', 'inconclusive', 'passed',
            ],
            'no name came back to compare' => [
                FixtureScenario::NoIdentityReturned, 'needs_review', 'passed', 'inconclusive',
            ],
            'an unreadable response' => [
                FixtureScenario::Unreadable, 'failed', null, null,
            ],
            'the provider could not read the file' => [
                FixtureScenario::UnreadableDocument, 'failed', null, null,
            ],
            'the provider is down' => [
                FixtureScenario::ProviderUnavailable, 'failed', null, null,
            ],
            'the provider timed out' => [
                FixtureScenario::ProviderTimeout, 'failed', null, null,
            ],
        ];
    }

    // --- Two findings, never merged -----------------------------------------

    public function test_a_document_that_passes_its_checks_can_still_name_somebody_else(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::NameMismatch);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/kyc")->json('data');

        // The document passed. The identity did not match. Both are true,
        // and one boolean would have lost whichever the customer needs.
        $this->assertSame('passed', $data['document_result']);
        $this->assertSame('failed', $data['identity_result']);
        $this->assertSame('needs_review', $data['status']);

        $name = $this->match($data, 'name');
        $this->assertSame('mismatched', $name['status']);
        $this->assertSame(
            FixtureKycVerificationProvider::HOLDER_NAME,
            $name['profile_value'],
        );
        $this->assertSame(
            FixtureKycVerificationProvider::MISMATCHED_NAME,
            $name['verified_value'],
        );
    }

    public function test_a_failed_document_check_is_never_a_fraud_finding(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::DocumentTampered);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/kyc")->getContent();

        // A provider's tamper signal is a finding about a document, not a
        // determination that a person committed fraud.
        foreach ([
            'fraud', 'fraudulent', 'forged', 'fake', 'criminal',
            'suspicious person', 'LAYOUT_MISMATCH', 'FONT_ANOMALY',
        ] as $accusation) {
            $this->assertStringNotContainsStringIgnoringCase(
                $accusation,
                $body,
                $accusation,
            );
        }

        // Nothing about the identity was concluded, because the document
        // that carried it did not pass.
        $this->assertSame('not_performed', json_decode($body, true)['data']['identity_result']);
    }

    public function test_a_failure_concludes_nothing_and_stores_no_matches(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::ProviderUnavailable);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/kyc")->json('data');

        $this->assertSame('failed', $data['status']);
        $this->assertNull($data['field_matches']);
        $this->assertDatabaseCount('kyc_field_matches', 0);

        $this->assertStringContainsString(
            'Nothing has been concluded about you',
            $data['message'],
        );
    }

    // --- What FynnEdge cannot compare ---------------------------------------

    public function test_a_field_fynnedge_does_not_hold_is_reported_not_omitted(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/kyc")->json('data');

        // FynnEdge holds no date of birth. Silence would let a customer
        // assume it was checked.
        $dob = $this->match($data, 'date_of_birth');
        $this->assertSame('unable_to_compare', $dob['status']);
        $this->assertStringContainsString(
            'does not hold your date of birth',
            $dob['reason'],
        );

        // And it is not stored either way.
        $this->assertNull($dob['profile_value']);
        $this->assertNull($dob['verified_value']);
    }

    public function test_a_matched_field_keeps_no_values_at_all(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $name = $this->match(
            $this->postJson("/api/v1/documents/{$id}/kyc")->json('data'),
            'name',
        );

        $this->assertSame('matched', $name['status']);

        // The comparison happened and the answer was "the same". Writing
        // the identity value down again would put it in the database for no
        // purpose.
        $this->assertNull($name['profile_value']);
        $this->assertNull($name['verified_value']);

        $stored = KycVerification::with('matches')->first();
        $this->assertNull(
            $stored->matches->firstWhere('field', 'name')->verified_value,
        );
    }

    public function test_a_profile_with_no_name_cannot_be_compared(): void
    {
        $user = $this->customer();
        $user->customerProfile->update(['full_name' => null]);
        $this->consent($user);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/kyc")->json('data');

        $name = $this->match($data, 'name');
        $this->assertSame('unable_to_compare', $name['status']);

        // Not a mismatch, and certainly not a pass.
        $this->assertSame('inconclusive', $data['identity_result']);
        $this->assertSame('needs_review', $data['status']);
    }

    // --- Not one byte more than the feature needs ---------------------------

    public function test_identity_data_the_feature_does_not_need_is_discarded(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/kyc")->getContent();

        // The fixture deliberately returns a whole identity document's
        // contents. Every one of these is listed in IdentityFieldPurpose
        // with the reason FynnEdge refuses it.
        foreach ([
            'AAAAA0000A', 'aadhaarNumber', '0000 0000 0000', 'idNumber',
            '1990-01-01', 'dateOfBirth', 'Invented Street', 'address',
            'Sample Parent', 'fatherName', 'photograph', 'SYNTHETIC',
            'gender', 'rawText', '0000000000',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "returned: {$leak}");
        }

        // Nor is any of it in the database.
        $stored = json_encode(KycVerification::with('matches')->get()->toArray());

        foreach ([
            'AAAAA0000A', '0000 0000 0000', '1990-01-01', 'Invented Street',
            'Sample Parent', 'SYNTHETIC', 'rawText',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $stored, "persisted: {$leak}");
        }
    }

    public function test_a_document_number_is_kept_only_as_a_masked_tail(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/kyc")->json('data');

        // Enough to tell one of the customer's own documents from another,
        // and not enough to be the identifier.
        $this->assertSame('XXXXXX000A', $data['masked_reference']);
        $this->assertStringNotContainsString(
            FixtureKycVerificationProvider::ID_NUMBER,
            json_encode($data),
        );

        $this->assertSame(
            'XXXXXX000A',
            KycVerification::first()->masked_reference,
        );
    }

    public function test_provider_vocabulary_never_reaches_the_customer(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();

        foreach ([
            "/api/v1/documents/{$id}/kyc",
            "/api/v1/documents/{$id}",
            '/api/v1/documents',
            '/api/v1/profile',
            '/api/v1/home',
            '/api/v1/fynn-score',
        ] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();

            foreach ([
                'checkOutcome', 'docAuthenticity', 'holderName', 'holder',
                'referenceId', 'engineVersion', 'FIXTURE-KYC',
                'INDETERMINATE', 'TAMPER_SUSPECTED',
            ] as $field) {
                $this->assertStringNotContainsString(
                    $field,
                    $body,
                    "{$uri} leaked the provider field: {$field}",
                );
            }
        }
    }

    public function test_nothing_about_storage_or_provider_internals_leaks(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/kyc")->getContent();

        foreach ([
            'storage_key', 'storage_disk', 'private', 'u'.$user->id.'/',
            'provider_reference', 'file_digest', 'method_version',
            'api_key', 'secret', 'endpoint', 'Stack trace',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body, $internal);
        }
    }

    // --- Language -----------------------------------------------------------

    public function test_a_pass_is_never_described_as_approval_or_as_proof(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/kyc")->getContent();

        foreach ([
            'approved', 'pre-approved', 'eligible', 'you qualify',
            'guaranteed', 'legitimate person', 'genuine person',
            'proves', 'proof of identity', 'loan approved',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $body, $claim);
        }

        // What it does say: the method, and the limits.
        $this->assertStringContainsString('ran its own checks', $body);
        $this->assertStringContainsString('not a lending decision', $body);
    }
}
