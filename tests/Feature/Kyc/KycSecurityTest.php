<?php

namespace Tests\Feature\Kyc;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Kyc\Fixture\FixtureScenario;
use App\Domain\Kyc\KycVerificationProvider;
use App\Domain\Kyc\VerificationType;
use App\Models\Document;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose document, whose decision, and what a request cannot decide.
 */
class KycSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.kyc.driver' => 'fixture']);
    }

    private function customer(string $name): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => $name, 'city' => 'Pune']);

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function consent(User $user): void
    {
        app(ConsentService::class)->grant(
            $user,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );
    }

    private function upload(string $type = 'identity'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'id.pdf', 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    // --- Ownership ----------------------------------------------------------

    public function test_another_customers_document_cannot_be_checked_or_read(): void
    {
        $owner = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($owner);
        $this->consent($owner);
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);
        $this->consent($intruder);

        // The same answer as a document that never existed. Nothing reveals
        // that a verification record exists at all.
        foreach ([
            ['get', "/api/v1/documents/{$id}/kyc", []],
            ['post', "/api/v1/documents/{$id}/kyc", []],
            ['post', "/api/v1/documents/{$id}/kyc/resolve", [
                'field' => 'name', 'decision' => 'update_profile',
            ]],
        ] as [$verb, $uri, $payload]) {
            $response = $verb === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri, $payload);

            $this->assertSame(404, $response->getStatusCode(), $uri);
            $this->assertStringNotContainsString(
                FixtureKycVerificationProvider::HOLDER_NAME,
                $response->getContent(),
            );
            $this->assertStringNotContainsString('XXXXXX000A', $response->getContent());
        }

        $this->assertSame(1, KycVerification::count());
    }

    public function test_a_forged_identity_in_the_payload_reaches_nothing(): void
    {
        $owner = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($owner);
        $this->consent($owner);
        $ownerDocument = $this->upload();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);
        $this->consent($intruder);
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();

        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'update_profile',
            'document_id' => $ownerDocument,
            'user_id' => $owner->id,
            'customer_id' => $owner->id,
            'confirmed_by' => $owner->id,
            'actor_id' => $owner->id,
        ]);

        // Whatever happened, it happened to nobody but the token holder —
        // and the owner's profile is untouched.
        $this->assertSame(
            FixtureKycVerificationProvider::HOLDER_NAME,
            $owner->customerProfile->fresh()->full_name,
        );

        $this->assertSame(
            $intruder->id,
            KycVerification::where('user_id', $intruder->id)->first()->user_id,
        );
    }

    public function test_every_kyc_route_requires_a_session(): void
    {
        foreach ([
            ['getJson', '/api/v1/documents/1/kyc', []],
            ['postJson', '/api/v1/documents/1/kyc', []],
            ['postJson', '/api/v1/documents/1/kyc/resolve', [
                'field' => 'name', 'decision' => 'keep_profile',
            ]],
        ] as [$verb, $uri, $payload]) {
            $response = $verb === 'getJson'
                ? $this->getJson($uri)
                : $this->postJson($uri, $payload);

            $this->assertSame(401, $response->getStatusCode(), $uri);
        }
    }

    // --- A client cannot author a verification ------------------------------

    public function test_a_customer_cannot_supply_their_own_verification(): void
    {
        $user = $this->customer('Somebody Else Entirely');
        $this->as($user);
        $this->consent($user);
        $this->scenario(FixtureScenario::NameMismatch);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/kyc", [
            // Everything a client might try to author.
            'status' => 'verified',
            'document_result' => 'passed',
            'identity_result' => 'passed',
            'match' => true,
            'provider' => 'real-provider',
            'is_sample' => false,
            'verification_type' => 'aadhaar_ekyc',
            'masked_reference' => 'XXXXX9999X',
            'field_matches' => [['field' => 'name', 'status' => 'matched']],
        ])->assertOk();

        $verification = KycVerification::first();

        // The provider's answer, not the customer's.
        $this->assertSame('fixture', $verification->provider);
        $this->assertTrue($verification->is_sample);
        $this->assertSame(
            VerificationType::IdentityDocument->value,
            $verification->verification_type,
        );
        $this->assertSame('needs_review', $verification->status);
        $this->assertSame('failed', $verification->identity_result);
        $this->assertSame('XXXXXX000A', $verification->masked_reference);
    }

    public function test_a_request_cannot_choose_the_provider_or_the_scenario(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $this->scenario(FixtureScenario::DocumentNotVerified);
        $id = $this->upload();

        foreach ([
            '?scenario=verified',
            '?fixture_scenario=verified',
            '?provider=fixture',
            '?driver=none',
            '?is_sample=0',
            '?skip_consent=1',
            '?user_id=1',
        ] as $query) {
            $this->postJson("/api/v1/documents/{$id}/kyc{$query}", ['force' => true]);

            $verification = KycVerification::latest('id')->first();

            // The configured scenario stands, and sample stays sample.
            $this->assertSame('not_verified', $verification->status, $query);
            $this->assertTrue($verification->is_sample, $query);
            $this->assertSame('fixture', $verification->provider, $query);
        }
    }

    public function test_the_sample_flag_survives_all_the_way_to_the_response(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.is_sample', true);

        $this->getJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.is_sample', true);
    }

    // --- No fallback --------------------------------------------------------

    public function test_a_real_driver_without_an_adapter_refuses_rather_than_using_the_fixture(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);

        // Configured for a provider that does not exist in this build.
        config(['fynnedge.kyc.driver' => 'some_real_partner']);
        $this->app->forgetInstance(KycVerificationProvider::class);

        $this->consent($user);
        $id = $this->upload();

        // Refused, not silently fulfilled with synthetic results.
        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertStatus(501)
            ->assertJsonPath('reason', 'verification_not_configured');

        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    // --- Cost control -------------------------------------------------------

    public function test_repeated_taps_do_not_repeat_the_provider_call(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();
        }

        // A result for these exact bytes already exists. Asking again would
        // spend money to learn nothing.
        $this->assertSame(1, KycVerification::count());
    }

    public function test_the_kyc_limiter_is_stricter_than_the_api(): void
    {
        config(['fynnedge.kyc.checks_per_hour' => 2]);

        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/kyc", ['force' => true])->assertOk();
        $this->postJson("/api/v1/documents/{$id}/kyc", ['force' => true])->assertOk();
        $this->postJson("/api/v1/documents/{$id}/kyc", ['force' => true])
            ->assertStatus(429);

        // Reading the state is free and stays available: it asks nobody.
        $this->getJson("/api/v1/documents/{$id}/kyc")->assertOk();
    }

    // --- Documents that change or go away -----------------------------------

    public function test_a_result_never_describes_a_file_it_was_not_taken_from(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();
        $this->getJson("/api/v1/documents/{$id}/kyc")
            ->assertJsonPath('data.is_stale', false);

        // The bytes behind the document change.
        $document = Document::find($id);
        $document->disk()->put($document->storage_key, '%PDF-1.4 different bytes');

        // Result A does not silently become result B.
        $this->getJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.is_stale', true);

        // A fresh check is allowed without force, because the held result
        // no longer describes what is stored.
        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();
        $this->assertSame(2, KycVerification::count());
    }

    public function test_a_deleted_document_can_no_longer_be_checked_or_read(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();
        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

        $this->getJson("/api/v1/documents/{$id}/kyc")->assertNotFound();
        $this->postJson("/api/v1/documents/{$id}/kyc")->assertNotFound();
        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'keep_profile',
        ])->assertNotFound();
    }

    public function test_a_missing_file_concludes_nothing_about_it(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $document = Document::find($id);
        $document->disk()->delete($document->storage_key);

        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'document_missing');

        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    // --- No unsupported document is half-checked ----------------------------

    public function test_only_identity_documents_are_verifiable(): void
    {
        $user = $this->customer(FixtureKycVerificationProvider::HOLDER_NAME);
        $this->as($user);
        $this->consent($user);

        foreach (['income', 'bank', 'loan', 'property', 'other'] as $type) {
            $id = $this->upload($type);

            $this->getJson("/api/v1/documents/{$id}/kyc")
                ->assertOk()
                ->assertJsonPath('data.is_supported', false)
                ->assertJsonPath('data.field_matches', null);

            $this->postJson("/api/v1/documents/{$id}/kyc")
                ->assertStatus(501)
                ->assertJsonPath('reason', 'document_type_not_verifiable');
        }

        $this->assertDatabaseCount('kyc_verifications', 0);
    }

    private function scenario(FixtureScenario $scenario): void
    {
        config(['fynnedge.kyc.fixture_scenario' => $scenario->value]);
        $this->app->forgetInstance(KycVerificationProvider::class);
    }
}
