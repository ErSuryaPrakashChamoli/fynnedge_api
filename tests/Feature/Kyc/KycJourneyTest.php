<?php

namespace Tests\Feature\Kyc;

use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Kyc\Fixture\FixtureScenario;
use App\Domain\Kyc\KycVerificationProvider;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vault → consent → verification → result → review → optional profile update.
 *
 * And the half that matters more: the same journey stopping short, with the
 * customer's profile exactly where it was.
 */
class KycJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.kyc.driver' => 'fixture']);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update([
            'full_name' => FixtureKycVerificationProvider::HOLDER_NAME,
            'city' => 'Pune',
            'age' => 34,
        ]);
        $user->financialProfile->update([
            'monthly_income' => 90000,
            'monthly_expenses' => 30000,
            'existing_emi' => 8000,
            'savings' => 200000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function upload(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'id.pdf', 'application/pdf', null, true),
            'type' => 'identity',
        ])->assertCreated()->json('data.id');
    }

    public function test_the_whole_journey_with_a_difference_to_resolve(): void
    {
        $user = $this->customer();

        config(['fynnedge.kyc.fixture_scenario' => FixtureScenario::NameMismatch->value]);
        $this->app->forgetInstance(KycVerificationProvider::class);

        // 1. A document in the vault. Stored, and nothing more.
        $id = $this->upload();

        $this->getJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.status', 'consent_required')
            ->assertJsonPath('data.field_matches', null);

        // 2. Its own consent, separate from anything Module 24 asked for.
        $this->postJson('/api/v1/consents', [
            'type' => 'identity_verification',
            'surface' => 'privacy_screen',
        ])->assertSuccessful();

        $this->getJson("/api/v1/documents/{$id}/kyc")
            ->assertJsonPath('data.status', 'not_started');

        // 3. The check the customer asked for.
        $result = $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();
        $result->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.is_sample', true)
            ->assertJsonPath('data.document_result', 'passed')
            ->assertJsonPath('data.identity_result', 'failed');

        // 4. The method travels with the result. "Verified" has to answer
        //    "verified how?" or it is a badge rather than a finding.
        $this->assertStringContainsString(
            'ran its own checks',
            $result->json('data.method'),
        );

        // 5. A difference, with both sides shown so the customer can decide.
        $difference = $result->json('data.differences')[0];
        $this->assertSame('name', $difference['field']);
        $this->assertSame(
            FixtureKycVerificationProvider::HOLDER_NAME,
            $difference['profile_value'],
        );
        $this->assertSame(
            FixtureKycVerificationProvider::MISMATCHED_NAME,
            $difference['verified_value'],
        );

        // 6. And at this point nothing about the customer has changed.
        $this->assertSame(
            FixtureKycVerificationProvider::HOLDER_NAME,
            $user->customerProfile->fresh()->full_name,
        );

        $scoreBefore = $this->getJson('/api/v1/fynn-score')->json('data');

        // 7. The customer looks at it and decides the document is right.
        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'update_profile',
        ])->assertOk();

        // 8. The profile changed, because they said so, through the
        //    ordinary profile service.
        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath(
                'data.full_name',
                FixtureKycVerificationProvider::MISMATCHED_NAME,
            );

        // 9. And a name is not a financial figure, so nothing financial
        //    moved. FynnScore is byte-identical.
        $this->assertSame(
            $scoreBefore,
            $this->getJson('/api/v1/fynn-score')->json('data'),
        );
        $this->assertCount(3, $scoreBefore['dimensions']);

        // 10. Home still answers, and says nothing about verification.
        $home = $this->getJson('/api/v1/home')->assertOk()->getContent();
        foreach (['verified', 'kyc', 'identity_result'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $home, $leak);
        }
    }

    public function test_a_clean_verification_alone_changes_nothing(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/consents', [
            'type' => 'identity_verification',
            'surface' => 'privacy_screen',
        ])->assertSuccessful();

        // Uploaded first, so the baseline isolates the verification. A
        // document in the vault legitimately changes Home's document count;
        // what must not change is anything that follows from checking it.
        $id = $this->upload();

        $before = [
            'profile' => $this->getJson('/api/v1/profile')->json('data'),
            'financial' => $this->getJson('/api/v1/financial-profile')->json('data'),
            'score' => $this->getJson('/api/v1/fynn-score')->json('data'),
            'home' => $this->getJson('/api/v1/home')->json('data'),
            'match' => $this->getJson(
                '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal'
            )->json('data'),
        ];

        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        // A verified identity is a fact about a document. It is not a
        // financial fact, and every one of these is byte-identical.
        $this->assertSame($before['profile'], $this->getJson('/api/v1/profile')->json('data'));
        $this->assertSame(
            $before['financial'],
            $this->getJson('/api/v1/financial-profile')->json('data'),
        );
        $this->assertSame($before['score'], $this->getJson('/api/v1/fynn-score')->json('data'));
        $this->assertSame($before['home'], $this->getJson('/api/v1/home')->json('data'));
        $this->assertSame(
            $before['match'],
            $this->getJson(
                '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal'
            )->json('data'),
        );
    }
}
