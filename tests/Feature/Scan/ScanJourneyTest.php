<?php

namespace Tests\Feature\Scan;

use App\Domain\Consent\ConsentType;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Vault → FynnScan → Extraction → Review → Confirm → Profile → Score → Match.
 *
 * And the thing this whole module is built to guarantee: that the arrow from
 * Extraction to Profile does not exist without the customer on it.
 */
class ScanJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.scan.driver' => 'fixture']);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['age' => 34]);
        // A tight starting position, so the score has room to move when the
        // customer's income turns out to be higher than they had entered.
        $user->financialProfile->update([
            'monthly_income' => 32000,
            'monthly_expenses' => 22000,
            'existing_emi' => 6000,
            'savings' => 40000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function upload(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');
    }

    public function test_the_whole_journey_from_a_stored_document_to_a_decision(): void
    {
        $user = $this->customer();

        // 1. A document in the vault. Stored, and nothing more.
        $id = $this->upload();

        $this->getJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->assertJsonPath('data.status', 'consent_required')
            ->assertJsonPath('data.extraction', null);

        // 2. Consent, purpose-limited to reading a document.
        $this->postJson('/api/v1/consents', [
            'type' => 'document_processing',
            'surface' => 'privacy_screen',
        ])->assertSuccessful();

        $this->getJson("/api/v1/documents/{$id}/scan")
            ->assertJsonPath('data.status', 'not_started');

        // 3. The reading the customer asked for.
        $scan = $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $scan->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.is_sample', true);

        // 4. Evidence, with where each value came from.
        $net = collect($scan->json('data.extraction'))
            ->firstWhere('key', 'net_income');
        $this->assertSame(75000, $net['amount']);
        $this->assertSame(1, $net['evidence']['page']);

        // 5. And at this point the customer's own figures are untouched.
        $scoreBefore = $this->getJson('/api/v1/fynn-score')->json('data.score');
        $this->assertSame('32000.00', (string) $user->financialProfile->fresh()->monthly_income);

        $matchBefore = $this->getJson(
            '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal'
        )->assertOk()->json('data.match_count');

        // 6. The customer looks at it and corrects it. Both facts survive.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'edited',
            'value' => '74000',
            'apply_to_profile' => true,
        ])->assertOk();

        $review = collect($this->getJson("/api/v1/documents/{$id}/scan")->json('data.reviews'))
            ->firstWhere('field_key', 'net_income');
        $this->assertSame('75000.00', $review['extracted_value']);
        $this->assertSame('74000.00', $review['customer_value']);
        $this->assertTrue($review['applied_to_profile']);

        // 7. The profile changed, because the customer said so.
        $this->getJson('/api/v1/financial-profile')
            ->assertOk()
            ->assertJsonPath('data.monthly_income', 74000);

        // 8. And everything downstream followed, through the ordinary path.
        $scoreAfter = $this->getJson('/api/v1/fynn-score')->json('data.score');
        $this->assertNotSame($scoreBefore, $scoreAfter);

        $this->assertSame(
            $scoreAfter,
            $this->getJson('/api/v1/home')->json('data.score.score'),
        );

        $matchAfter = $this->getJson(
            '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal'
        )->assertOk()->json('data.match_count');
        $this->assertGreaterThanOrEqual($matchBefore, $matchAfter);

        // 9. FynnScore is still FynnEdge's own: no document, no confidence
        //    and no extraction became a dimension of it.
        $score = $this->getJson('/api/v1/fynn-score')->assertOk();
        $this->assertCount(3, $score->json('data.dimensions'));

        foreach (['confidence', 'extraction', 'document', 'scan', 'ocr'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase(
                $leak,
                $score->getContent(),
                "FynnScore mentions {$leak}",
            );
        }
    }

    public function test_reading_a_document_alone_reaches_nothing(): void
    {
        $user = $this->customer();

        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        $before = [
            'profile' => $this->getJson('/api/v1/financial-profile')->json('data'),
            'score' => $this->getJson('/api/v1/fynn-score')->json('data'),
            'home' => $this->getJson('/api/v1/home')->json('data.score'),
            'summary' => $this->getJson('/api/v1/financial-summary')->json('data'),
        ];

        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        // Confirming, without asking for it to be saved.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
        ])->assertOk();

        // Everything the customer's figures drive is byte-identical. OCR
        // ran, a value was confirmed as correctly read, and none of it
        // became the customer's financial data.
        $this->assertSame(
            $before['profile'],
            $this->getJson('/api/v1/financial-profile')->json('data'),
        );
        $this->assertSame(
            $before['score'],
            $this->getJson('/api/v1/fynn-score')->json('data'),
        );
        $this->assertSame(
            $before['home'],
            $this->getJson('/api/v1/home')->json('data.score'),
        );
        $this->assertSame(
            $before['summary'],
            $this->getJson('/api/v1/financial-summary')->json('data'),
        );
    }

    public function test_an_application_is_never_created_or_altered_by_a_reading(): void
    {
        $user = $this->customer();
        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        $applicationId = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 500000,
            'tenure_months' => 48,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/applications/{$applicationId}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        $snapshot = $this->getJson("/api/v1/applications/{$applicationId}")->json('data');

        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
        ])->assertOk();

        // No new application, and the submitted one is untouched — its
        // figures are what the customer stated when they submitted it.
        $this->assertCount(1, $this->getJson('/api/v1/applications')->json('data'));
        $this->assertSame(
            $snapshot,
            $this->getJson("/api/v1/applications/{$applicationId}")->json('data'),
        );
    }

    public function test_fynn_ai_never_receives_the_document_or_what_was_read(): void
    {
        $user = $this->customer();
        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );
        app(ConsentService::class)->grant($user, ConsentType::AiMemory, 'privacy_screen');

        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        // Module 13's boundary: FynnAI sees document metadata, never
        // contents, and reading a document does not change that.
        $context = $this->getJson('/api/v1/ai/context')->assertOk()->getContent();

        foreach ([
            'Northwind', 'Sample Employee', '75000.00', '85000.00',
            'net_income', 'gross_income', 'extraction', 'AAAAA0000A',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $context, $leak);
        }
    }
}
