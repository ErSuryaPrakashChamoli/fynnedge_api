<?php

namespace Tests\Feature\Kyc;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\CanonicalVerification;
use App\Domain\Kyc\CheckOutcome;
use App\Domain\Kyc\FieldMatch;
use App\Domain\Kyc\KycNormalizer;
use App\Domain\Kyc\VerificationType;
use App\Models\CustomerProfile;
use App\Models\KycFieldMatch;
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
 * An adapter that returns what FynnEdge refuses gets nothing stored.
 *
 * The unit tests fix the rule; this one proves it survives the whole live
 * path — provider, normalizer, service, database, response. It stands in an
 * adapter that behaves the way a real one plausibly would: a provider
 * returns a full identity document, somebody maps its fields
 * conscientiously, and a PAN and an address arrive at FynnEdge's door.
 *
 * The customer sees an ordinary "could not verify". The reason stays
 * server-side, because "your verification service sent us a field we
 * refuse" is FynnEdge's problem to fix, not something a customer can act
 * on.
 */
class IdentityBoundaryEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.kyc.driver' => 'fixture']);
        $this->customer();
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        Sanctum::actingAs($user);

        app(ConsentService::class)
            ->grant($user, ConsentType::IdentityVerification, 'privacy_screen');

        return $user;
    }

    private function stored(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'identity.pdf', 'application/pdf', null, true),
            'type' => 'identity',
        ])->assertCreated()->json('data.id');
    }

    /** An adapter that maps a provider's fields without reading this project's. */
    private function overzealousNormalizer(callable $result): void
    {
        $this->app->bind(KycNormalizer::class, fn () => new class($result) implements KycNormalizer
        {
            public function __construct(private $result) {}

            public function type(): VerificationType
            {
                return VerificationType::IdentityDocument;
            }

            public function normalize(
                array $response,
                ?CustomerProfile $profile,
            ): CanonicalVerification {
                return ($this->result)();
            }
        });
    }

    private function verificationOf(array $matches, ?string $reference = 'XXXXXX000A'): callable
    {
        return fn () => new CanonicalVerification(
            type: VerificationType::IdentityDocument,
            documentResult: CheckOutcome::Passed,
            identityResult: CheckOutcome::Passed,
            fieldMatches: $matches,
            checkedAt: now(),
            maskedReference: $reference,
        );
    }

    private function assertRefusedAndNothingKept(string $mustNotAppear): void
    {
        $id = $this->stored();

        $response = $this->postJson("/api/v1/documents/{$id}/kyc")->assertOk();

        // An ordinary failure, not a stack trace and not a lecture.
        $this->assertSame('failed', $response->json('data.status'));
        $this->assertStringNotContainsString(
            $mustNotAppear,
            $response->getContent(),
            'the refused value came back in the response',
        );

        // Nothing about the identity survived anywhere.
        $this->assertSame(0, KycFieldMatch::count());
        $this->assertStringNotContainsString(
            $mustNotAppear,
            json_encode(KycVerification::with('matches')->get()->toArray()),
            'the refused value was persisted',
        );
    }

    public function test_a_pan_number_offered_as_a_field_value_is_refused(): void
    {
        $this->overzealousNormalizer($this->verificationOf([
            FieldMatch::matched('name', 'Name'),
            FieldMatch::mismatched(
                'document_number',
                'PAN',
                'unknown',
                'AAAAA0000A',
            ),
        ]));

        $this->assertRefusedAndNothingKept('AAAAA0000A');
    }

    public function test_an_address_offered_as_a_field_value_is_refused(): void
    {
        $this->overzealousNormalizer($this->verificationOf([
            FieldMatch::mismatched('address', 'Address', 'Pune', '12 Invented Street'),
        ]));

        $this->assertRefusedAndNothingKept('Invented Street');
    }

    public function test_an_aadhaar_field_is_refused_even_with_no_value(): void
    {
        $this->overzealousNormalizer($this->verificationOf([
            FieldMatch::unableToCompare('aadhaar_number', 'Aadhaar', 'not checked'),
        ]));

        $this->assertRefusedAndNothingKept('aadhaar_number');
    }

    public function test_an_unmasked_document_reference_is_refused(): void
    {
        $this->overzealousNormalizer($this->verificationOf(
            [FieldMatch::matched('name', 'Name')],
            reference: 'AAAAA0000A',
        ));

        $this->assertRefusedAndNothingKept('AAAAA0000A');
    }

    public function test_a_conforming_result_still_gets_through(): void
    {
        // The refusals above mean nothing unless this passes: a boundary
        // that rejects everything is not a boundary.
        $this->overzealousNormalizer($this->verificationOf([
            FieldMatch::matched('name', 'Name'),
            FieldMatch::unableToCompare(
                'date_of_birth',
                'Date of birth',
                'FynnEdge does not hold your date of birth.',
            ),
        ]));

        $id = $this->stored();

        $this->postJson("/api/v1/documents/{$id}/kyc")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.masked_reference', 'XXXXXX000A');

        $this->assertSame(2, KycFieldMatch::count());
    }
}
