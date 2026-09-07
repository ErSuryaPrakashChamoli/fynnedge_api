<?php

namespace Tests\Feature\Api;

use App\Domain\Scan\ScanStatus;
use App\Domain\Scan\UnavailableDocumentUnderstandingProvider;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentScanTest extends TestCase
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
        Sanctum::actingAs($user);

        return $user;
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return new UploadedFile($path, 'statement.pdf', 'application/pdf', null, true);
    }

    private function storedDocument(): string
    {
        return $this->postJson('/api/v1/documents', [
            'file' => $this->pdf(),
            'type' => 'bank',
        ])->assertCreated()->json('data.id');
    }

    // --- The honest answer --------------------------------------------------

    public function test_reading_the_scan_state_says_nothing_has_read_it(): void
    {
        $this->customer();
        $id = $this->storedDocument();

        $data = $this->getJson('/api/v1/documents/'.$id.'/scan')
            ->assertOk()
            ->json('data');

        $this->assertSame('unavailable', $data['status']);
        $this->assertSame('Not connected', $data['status_label']);
        $this->assertNull($data['provider']);
        $this->assertSame(
            UnavailableDocumentUnderstandingProvider::REASON,
            $data['reason'],
        );
        $this->assertStringContainsString('stored safely', $data['message']);
        $this->assertStringContainsString('Nothing has been extracted', $data['message']);
    }

    public function test_asking_for_a_scan_does_not_pretend_one_started(): void
    {
        $this->customer();
        $id = $this->storedDocument();

        $response = $this->postJson('/api/v1/documents/'.$id.'/scan');

        // 501: the server does not implement this yet. Not 200 with an empty
        // result, which downstream would read as a scan that found nothing.
        $response->assertStatus(501);
        $response->assertJsonPath(
            'reason',
            UnavailableDocumentUnderstandingProvider::REASON,
        );

        // Nothing about a scan comes back, because none was created.
        $this->assertNull($response->json('data'));
    }

    public function test_no_extraction_and_no_confidence_is_ever_returned(): void
    {
        $this->customer();
        $id = $this->storedDocument();

        foreach ([
            $this->getJson('/api/v1/documents/'.$id.'/scan'),
            $this->postJson('/api/v1/documents/'.$id.'/scan'),
        ] as $response) {
            $this->assertNull($response->json('data.extraction'));
            $this->assertNull($response->json('data.confidence'));

            // And nothing that looks like a reading of a financial document.
            $body = mb_strtolower($response->getContent());

            foreach ([
                'pan', 'aadhaar', 'account_number', 'person_name',
                'balance', 'salary', 'ifsc', '%',
            ] as $fabricated) {
                $this->assertStringNotContainsString(
                    $fabricated,
                    $body,
                    "the scan response contains '{$fabricated}'"
                );
            }
        }
    }

    public function test_only_the_two_reachable_statuses_can_occur(): void
    {
        $this->customer();
        $id = $this->storedDocument();

        $status = ScanStatus::from(
            $this->getJson('/api/v1/documents/'.$id.'/scan')->json('data.status')
        );

        // Queued, processing, completed and failed are a provider's to
        // report, and there is no provider.
        $this->assertFalse($status->requiresProvider());
    }

    public function test_nothing_leaks_about_storage_or_the_provider(): void
    {
        $this->customer();
        $id = $this->storedDocument();
        $document = Document::find($id);

        foreach ([
            $this->getJson('/api/v1/documents/'.$id.'/scan'),
            $this->postJson('/api/v1/documents/'.$id.'/scan'),
        ] as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString($document->storage_key, $body);
            $this->assertStringNotContainsString('storage_key', $body);
            $this->assertStringNotContainsString('storage_disk', $body);
            $this->assertStringNotContainsString(storage_path(), $body);

            // No vendor, endpoint or credential detail either.
            foreach (['api_key', 'secret', 'endpoint', 'textract', 'vision', 'openai'] as $internal) {
                $this->assertStringNotContainsString($internal, mb_strtolower($body));
            }
        }
    }

    // --- Ownership ----------------------------------------------------------

    public function test_scanning_requires_a_session(): void
    {
        $this->customer();
        $id = $this->storedDocument();
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/documents/'.$id.'/scan')->assertStatus(401);
        $this->postJson('/api/v1/documents/'.$id.'/scan')->assertStatus(401);
    }

    public function test_another_customers_document_cannot_be_scanned(): void
    {
        $victim = $this->customer();
        $theirs = $this->storedDocument();

        $this->customer();

        $missing = $this->postJson('/api/v1/documents/999999/scan');
        $notMine = $this->postJson('/api/v1/documents/'.$theirs.'/scan');

        // Identical to a document that does not exist.
        $notMine->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->json('message'), $notMine->json('message'));

        $this->getJson('/api/v1/documents/'.$theirs.'/scan')->assertNotFound();
        $this->assertSame($victim->id, Document::find($theirs)->user_id);
    }

    public function test_a_user_id_in_the_payload_cannot_reach_another_vault(): void
    {
        $victim = $this->customer();
        $theirs = $this->storedDocument();

        $this->customer();

        $this->postJson('/api/v1/documents/'.$theirs.'/scan', [
            'user_id' => $victim->id,
            'customer_id' => $victim->id,
            'owner_id' => $victim->id,
        ])->assertNotFound();
    }

    public function test_a_deleted_document_cannot_be_scanned_or_inspected(): void
    {
        $this->customer();
        $id = $this->storedDocument();

        $this->deleteJson('/api/v1/documents/'.$id)->assertNoContent();

        // Soft-deleted: gone from the customer's API entirely, scan included.
        $this->getJson('/api/v1/documents/'.$id.'/scan')->assertNotFound();
        $this->postJson('/api/v1/documents/'.$id.'/scan')->assertNotFound();
        $this->assertNotNull(Document::withTrashed()->find($id)->deleted_at);
    }

    public function test_a_document_that_never_existed_is_a_404(): void
    {
        $this->customer();

        $this->getJson('/api/v1/documents/424242/scan')->assertNotFound();
        $this->postJson('/api/v1/documents/424242/scan')->assertNotFound();
    }

    // --- The document is untouched ------------------------------------------

    public function test_asking_for_a_scan_changes_nothing_about_the_document(): void
    {
        $this->customer();
        $id = $this->storedDocument();
        $before = $this->getJson('/api/v1/documents/'.$id)->json('data');

        $this->postJson('/api/v1/documents/'.$id.'/scan')->assertStatus(501);

        $after = $this->getJson('/api/v1/documents/'.$id)->json('data');

        $this->assertSame($before, $after);
        $this->assertFalse($after['is_verified']);
        $this->assertFalse($after['is_shared']);
        $this->assertSame('uploaded', $after['status']);

        // And it still downloads.
        $this->get('/api/v1/documents/'.$id.'/download')->assertOk();
    }

    public function test_no_scan_rows_are_written_for_something_that_never_ran(): void
    {
        $this->customer();
        $id = $this->storedDocument();

        $this->postJson('/api/v1/documents/'.$id.'/scan')->assertStatus(501);
        $this->getJson('/api/v1/documents/'.$id.'/scan')->assertOk();

        // The table exists now — a provider can be configured — but a
        // refusal writes nothing to it. Recording "nothing happened" per
        // document would be storage without information, and a row here
        // would later read as an attempt that was actually made.
        $this->assertTrue(Schema::hasTable('document_scans'));
        $this->assertDatabaseCount('document_scans', 0);
        $this->assertDatabaseCount('document_scan_fields', 0);
    }
}
