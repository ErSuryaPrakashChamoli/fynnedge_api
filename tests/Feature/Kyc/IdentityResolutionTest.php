<?php

namespace Tests\Feature\Kyc;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Kyc\Fixture\FixtureScenario;
use App\Domain\Kyc\KycVerificationProvider;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A verification is evidence. The customer stays the authority over their
 * own profile.
 *
 * Everything here asks one question in different ways: can something a
 * verification service reported change what FynnEdge holds about a customer,
 * without the customer choosing it?
 */
class IdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE_NAME = 'Sample Customer';

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

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update([
            'full_name' => self::PROFILE_NAME,
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

        app(ConsentService::class)->grant(
            $user,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );

        return $user;
    }

    private function verified(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        $id = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'id.pdf', 'application/pdf', null, true),
            'type' => 'identity',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();

        return $id;
    }

    private function profileName(User $user): ?string
    {
        return $user->customerProfile->fresh()->full_name;
    }

    // --- Verification alone changes nothing ---------------------------------

    public function test_a_verification_never_touches_the_profile(): void
    {
        $user = $this->customer();
        $before = $this->getJson('/api/v1/profile')->json('data');

        $this->verified();

        $this->assertSame(self::PROFILE_NAME, $this->profileName($user));
        $this->assertSame(
            $before,
            $this->getJson('/api/v1/profile')->json('data'),
            'a verification changed the customer profile',
        );
    }

    public function test_a_mismatch_never_rewrites_the_profile_with_the_document(): void
    {
        $user = $this->customer();
        $this->scenario(FixtureScenario::NameMismatch);

        $id = $this->verified();

        // The verified name is right there in the result, and FynnEdge did
        // not take it. The customer has a difference to look at, not a
        // change to discover.
        $this->assertSame(self::PROFILE_NAME, $this->profileName($user));

        $data = $this->getJson("/api/v1/documents/{$id}/kyc")->json('data');
        $this->assertCount(1, $data['differences']);
        $this->assertSame(
            FixtureKycVerificationProvider::MISMATCHED_NAME,
            $data['differences'][0]['verified_value'],
        );
    }

    // --- Only the customer resolves it --------------------------------------

    public function test_keeping_the_profile_is_a_recorded_decision(): void
    {
        $user = $this->customer();
        $this->scenario(FixtureScenario::NameMismatch);
        $id = $this->verified();

        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'keep_profile',
        ])->assertOk();

        $this->assertSame(self::PROFILE_NAME, $this->profileName($user));

        // The evidence is unchanged. A decision about a finding does not
        // edit the finding.
        $data = $this->getJson("/api/v1/documents/{$id}/kyc")->json('data');
        $name = collect($data['field_matches'])->firstWhere('field', 'name');

        $this->assertSame('mismatched', $name['status']);
        $this->assertSame(
            FixtureKycVerificationProvider::MISMATCHED_NAME,
            $name['verified_value'],
        );
        $this->assertStringContainsString('You confirmed', $name['reason']);
    }

    public function test_updating_the_profile_happens_only_when_chosen(): void
    {
        $user = $this->customer();
        $this->scenario(FixtureScenario::NameMismatch);
        $id = $this->verified();

        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'update_profile',
        ])->assertOk();

        $this->assertSame(
            FixtureKycVerificationProvider::MISMATCHED_NAME,
            $this->profileName($user),
        );

        // And it went down the ordinary route, so the ordinary endpoint
        // agrees.
        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath(
                'data.full_name',
                FixtureKycVerificationProvider::MISMATCHED_NAME,
            );
    }

    public function test_resolving_a_field_with_no_difference_is_refused(): void
    {
        $user = $this->customer();
        $id = $this->verified();

        // The name matched. There is nothing to resolve, and writing the
        // verified value anyway would be a change nobody asked for.
        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'update_profile',
        ])->assertStatus(422);

        $this->assertSame(self::PROFILE_NAME, $this->profileName($user));
    }

    public function test_a_field_with_nowhere_to_go_is_refused(): void
    {
        $user = $this->customer();
        $this->scenario(FixtureScenario::NameMismatch);
        $id = $this->verified();

        // FynnEdge holds no date of birth. Adding a column so a verification
        // result had somewhere to land is how a product ends up storing
        // identity data it never needed.
        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'date_of_birth',
            'decision' => 'update_profile',
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'field_not_resolvable');
    }

    public function test_an_unknown_field_is_a_404(): void
    {
        $this->customer();
        $id = $this->verified();

        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'aadhaar_number',
            'decision' => 'update_profile',
        ])->assertStatus(404);
    }

    public function test_a_verified_name_still_passes_the_ordinary_validation(): void
    {
        $user = $this->customer();
        $this->scenario(FixtureScenario::NameMismatch);
        $id = $this->verified();

        // A name a verification service returned gets no easier a path into
        // the profile than a typed one.
        $user->kycVerifications()->first()->matches()
            ->where('field', 'name')
            ->update(['verified_value' => '<script>alert(1)</script>']);

        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'update_profile',
        ])->assertStatus(422);

        $this->assertSame(self::PROFILE_NAME, $this->profileName($user));
    }

    public function test_resolving_twice_leaves_one_profile(): void
    {
        $user = $this->customer();
        $this->scenario(FixtureScenario::NameMismatch);
        $id = $this->verified();

        $payload = ['field' => 'name', 'decision' => 'update_profile'];

        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", $payload)->assertOk();

        // The second tap finds no difference left to resolve, because the
        // profile now agrees with the document.
        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", $payload)->assertOk();

        $this->assertSame(
            FixtureKycVerificationProvider::MISMATCHED_NAME,
            $this->profileName($user),
        );
        $this->assertDatabaseCount('kyc_verifications', 1);
    }

    // --- Everything downstream stays where it was ---------------------------

    public function test_verification_does_not_move_the_fynn_score(): void
    {
        $this->customer();

        $before = $this->getJson('/api/v1/fynn-score')->json('data');
        $home = $this->getJson('/api/v1/home')->json('data.score');

        $this->verified();

        // A verified identity says nothing about financial health, and
        // FynnScore is FynnEdge's view of financial health.
        $this->assertSame($before, $this->getJson('/api/v1/fynn-score')->json('data'));
        $this->assertSame($home, $this->getJson('/api/v1/home')->json('data.score'));

        $this->assertCount(3, $before['dimensions']);
    }

    public function test_verification_does_not_reach_applications(): void
    {
        $this->customer();

        $applicationId = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 500000,
            'tenure_months' => 48,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$applicationId}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        $snapshot = $this->getJson("/api/v1/applications/{$applicationId}")->json('data');

        $id = $this->verified();
        $this->postJson("/api/v1/documents/{$id}/kyc/resolve", [
            'field' => 'name',
            'decision' => 'keep_profile',
        ])->assertOk();

        // No application created, none altered, no status moved, and the
        // submitted snapshot untouched.
        $this->assertCount(1, $this->getJson('/api/v1/applications')->json('data'));
        $this->assertSame(
            $snapshot,
            $this->getJson("/api/v1/applications/{$applicationId}")->json('data'),
        );
    }

    public function test_fynn_ai_never_receives_identity_data(): void
    {
        $user = $this->customer();
        app(ConsentService::class)->grant($user, ConsentType::AiMemory, 'privacy_screen');

        $this->verified();

        // Module 13's boundary, and a verification does not change it.
        $context = $this->getJson('/api/v1/ai/context')->assertOk()->getContent();

        foreach ([
            'AAAAA0000A', '0000 0000 0000', '1990-01-01', 'XXXXXX000A',
            'FIXTURE-KYC', 'verification', 'identity_result', 'kyc',
            'Invented Street', 'Sample Parent',
        ] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $context, $leak);
        }
    }

    public function test_a_scan_and_a_verification_coexist_without_touching(): void
    {
        config(['fynnedge.scan.driver' => 'fixture']);

        $user = $this->customer();
        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        // FynnScan reads income documents; KYC checks identity documents.
        // One vault, two independent records, neither aware of the other.
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        $payslip = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$payslip}/scan")->assertOk();
        $identity = $this->verified();

        // Each document has the record for its own capability and nothing
        // for the other's.
        $this->getJson("/api/v1/documents/{$payslip}/scan")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->getJson("/api/v1/documents/{$payslip}/kyc")
            ->assertOk()
            ->assertJsonPath('data.is_supported', false);

        $this->getJson("/api/v1/documents/{$identity}/kyc")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');

        $this->getJson("/api/v1/documents/{$identity}/scan")
            ->assertOk()
            ->assertJsonPath('data.is_supported', false);

        $this->assertDatabaseCount('document_scans', 1);
        $this->assertDatabaseCount('kyc_verifications', 1);
    }
}
