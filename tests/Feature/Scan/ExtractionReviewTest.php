<?php

namespace Tests\Feature\Scan;

use App\Domain\Consent\ConsentType;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Models\DocumentScanReview;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Extraction is evidence. The customer decides whether it is their data.
 *
 * This is the boundary Module 24 exists to hold. Everything here asks one
 * question in different ways: can a figure a machine read off a document
 * reach a customer's financial profile without the customer saying so?
 */
class ExtractionReviewTest extends TestCase
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
        $user->financialProfile->update([
            'monthly_income' => 60000,
            'monthly_expenses' => 20000,
            'existing_emi' => 5000,
            'savings' => 100000,
        ]);
        Sanctum::actingAs($user);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        return $user;
    }

    private function scanned(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        $id = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        return $id;
    }

    private function income(User $user): string
    {
        return (string) $user->financialProfile->fresh()->monthly_income;
    }

    // --- OCR alone is not a profile update ----------------------------------

    public function test_reading_a_document_never_touches_the_profile(): void
    {
        $user = $this->customer();
        $before = $this->getJson('/api/v1/financial-profile')->json('data');

        $this->scanned();

        $this->assertSame('60000.00', $this->income($user));
        $this->assertSame(
            $before,
            $this->getJson('/api/v1/financial-profile')->json('data'),
            'a scan changed the customer\'s financial profile',
        );
    }

    public function test_confirming_a_field_does_not_by_itself_change_the_profile(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        // Confirming records that the document was read correctly. Saving
        // that figure to a profile is a further, separate act.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
        ])->assertOk();

        $this->assertSame('60000.00', $this->income($user));

        $review = DocumentScanReview::where('field_key', 'net_income')->first();
        $this->assertSame('confirmed', $review->decision);
        $this->assertFalse($review->applied_to_profile);
    }

    public function test_only_an_explicit_request_carries_a_figure_into_the_profile(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
        ])->assertOk();

        $this->assertSame('75000.00', $this->income($user));
        $this->assertTrue(
            DocumentScanReview::where('field_key', 'net_income')->first()->applied_to_profile,
        );
    }

    // --- A correction is a second fact, not an overwrite --------------------

    public function test_an_edit_keeps_both_what_was_read_and_what_the_customer_says(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'edited',
            'value' => '72000',
            'apply_to_profile' => true,
        ])->assertOk();

        $review = DocumentScanReview::where('field_key', 'net_income')->first();

        // Both survive. What the document appeared to say and what the
        // customer says it is are two facts, not one correcting the other.
        $this->assertSame('75000.00', $review->extracted_value);
        $this->assertSame('72000.00', $review->customer_value);
        $this->assertSame(7200000, $review->customer_amount_paise);

        // And the extraction itself is untouched.
        $data = $this->getJson("/api/v1/documents/{$id}/scan")->json('data');
        $net = collect($data['extraction'])->firstWhere('key', 'net_income');
        $this->assertSame('75000.00', $net['value']);

        $this->assertSame('72000.00', $this->income($user));
    }

    public function test_rejecting_supplies_no_value_and_changes_nothing(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'rejected',
        ])->assertOk();

        $review = DocumentScanReview::where('field_key', 'net_income')->first();
        $this->assertSame('rejected', $review->decision);
        $this->assertNull($review->customer_value);
        $this->assertFalse($review->applied_to_profile);

        $this->assertSame('60000.00', $this->income($user));
    }

    public function test_rejecting_does_not_retry_the_provider(): void
    {
        $this->customer();
        $id = $this->scanned();

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'rejected',
        ])->assertOk();

        // Rejecting is a judgement, not a request. Asking the provider again
        // is a separate, explicit action.
        $this->assertDatabaseCount('document_scans', 1);
    }

    // --- Confirmed does not mean unchecked ----------------------------------

    public function test_a_confirmed_figure_still_passes_the_ordinary_validation(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        foreach (['abc', '-5000', '999999999999', ''] as $bad) {
            $this->postJson("/api/v1/documents/{$id}/scan/review", [
                'field' => 'net_income',
                'decision' => 'edited',
                'value' => $bad,
                'apply_to_profile' => true,
            ])->assertStatus(422);
        }

        $this->assertSame('60000.00', $this->income($user));
    }

    public function test_a_field_with_nowhere_to_go_is_refused_not_guessed(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        // Gross pay is shown and confirmable as a record of what the
        // document said. It reaches no profile field, because FynnEdge has
        // no rule saying a customer's monthly income is their gross — and
        // inventing one here would be a financial decision taken by an OCR
        // pipeline.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'gross_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
        ])->assertStatus(422)
            ->assertJsonPath('reason', 'field_not_applicable');

        $this->assertSame('60000.00', $this->income($user));

        // But confirming it without applying is fine: it records that the
        // document was read correctly.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'gross_income',
            'decision' => 'confirmed',
        ])->assertOk();
    }

    public function test_a_field_the_document_did_not_show_cannot_be_confirmed(): void
    {
        config(['fynnedge.scan.fixture_scenario' => 'missing_field']);
        $this->app->forgetInstance(DocumentUnderstandingProvider::class);

        $user = $this->customer();
        $id = $this->scanned();

        // The field exists — the reading reports that the document does not
        // show it — but there is nothing to agree with, so confirming is
        // refused rather than recording agreement with a blank.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
        ])->assertStatus(422);

        $this->assertDatabaseCount('document_scan_reviews', 0);
        $this->assertSame('60000.00', $this->income($user));

        // Supplying the figure themselves is the way forward, and works.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'edited',
            'value' => '68000',
            'apply_to_profile' => true,
        ])->assertOk();

        $this->assertSame('68000.00', $this->income($user));
    }

    // --- Two taps are one decision ------------------------------------------

    public function test_confirming_twice_updates_the_profile_once(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        $payload = [
            'field' => 'net_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
        ];

        $this->postJson("/api/v1/documents/{$id}/scan/review", $payload)->assertOk();

        // A different figure arrives by hand in between. A second tap on a
        // decision already applied must not silently undo it.
        $user->financialProfile->update(['monthly_income' => 90000]);

        $this->postJson("/api/v1/documents/{$id}/scan/review", $payload)->assertOk();

        $this->assertSame('90000.00', $this->income($user));
        $this->assertDatabaseCount('document_scan_reviews', 1);
    }

    public function test_rejecting_twice_leaves_one_decision(): void
    {
        $this->customer();
        $id = $this->scanned();

        $payload = ['field' => 'net_income', 'decision' => 'rejected'];

        $this->postJson("/api/v1/documents/{$id}/scan/review", $payload)->assertOk();
        $this->postJson("/api/v1/documents/{$id}/scan/review", $payload)->assertOk();

        $this->assertDatabaseCount('document_scan_reviews', 1);
    }

    public function test_a_change_of_mind_replaces_the_standing_decision(): void
    {
        $user = $this->customer();
        $id = $this->scanned();

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
        ])->assertOk();

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'edited',
            'value' => '71000',
            'apply_to_profile' => true,
        ])->assertOk();

        $this->assertDatabaseCount('document_scan_reviews', 1);
        $this->assertSame('71000.00', $this->income($user));
    }

    // --- Confirmation flows through the ordinary machinery ------------------

    public function test_a_confirmed_income_moves_the_score_the_ordinary_way(): void
    {
        $user = $this->customer();

        // A starting point far enough from the document's figure that the
        // score has room to move.
        $user->financialProfile->update(['monthly_income' => 32000]);

        $before = $this->getJson('/api/v1/fynn-score')->json('data.score');

        $id = $this->scanned();

        // Unchanged by the reading alone.
        $this->assertSame($before, $this->getJson('/api/v1/fynn-score')->json('data.score'));

        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
        ])->assertOk();

        // Changed by the confirmation, because the customer's income
        // genuinely changed — through the same service, validation and
        // recalculation a typed figure uses.
        $after = $this->getJson('/api/v1/fynn-score')->json('data.score');
        $this->assertNotSame($before, $after);

        // Home agrees, because there is one invalidation path and this went
        // down it.
        $this->assertSame(
            $after,
            $this->getJson('/api/v1/home')->json('data.score.score'),
        );

        // And the score is still FynnEdge's own: no document, no confidence
        // and no extraction became a dimension of it.
        $this->assertCount(3, $this->getJson('/api/v1/fynn-score')->json('data.dimensions'));
    }

    public function test_the_language_never_promises_verification(): void
    {
        $this->customer();
        $id = $this->scanned();

        $body = $this->getJson("/api/v1/documents/{$id}/scan")->getContent();

        foreach ([
            'verified', 'authentic', 'genuine', 'validated',
            'we confirmed', 'proven', 'accurate',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $body, $claim);
        }

        // What it does say.
        $this->assertStringContainsString('does not verify your document', $body);
        $this->assertStringContainsString('until you confirm it', $body);
    }
}
