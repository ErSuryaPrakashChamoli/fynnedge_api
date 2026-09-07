<?php

namespace Tests\Feature\Scan;

use App\Domain\Consent\ConsentType;
use App\Domain\Scan\ScanStatus;
use App\Jobs\ProcessDocumentScan;
use App\Models\Document;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use App\Services\Scan\DocumentUnderstandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reading a document happens away from the customer's request.
 *
 * Module 24 shipped this synchronously and recorded the queue as a
 * production prerequisite. The reason it mattered was never speed: it was
 * that a worker running later must re-check everything the customer's
 * request checked, because by the time it runs the document may be gone,
 * replaced, or no longer permitted. None of that could be enforced while
 * the work happened inside the request that authorised it.
 */
class QueuedScanTest extends TestCase
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
        Sanctum::actingAs($user);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        return $user;
    }

    private function upload(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');
    }

    // --- The request hands the work off --------------------------------------

    public function test_the_request_queues_the_work_and_does_not_do_it(): void
    {
        Queue::fake();

        $this->customer();
        $id = $this->upload();

        $data = $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertAccepted()
            ->json('data');

        // Queued, not processing: nothing has been asked of a provider yet,
        // and a status claiming otherwise would say work had started before
        // it had.
        $this->assertSame('queued', $data['status']);
        $this->assertNull($data['extraction']);

        Queue::assertPushed(ProcessDocumentScan::class, 1);

        $scan = DocumentScan::sole();
        $this->assertSame(ScanStatus::Queued->value, $scan->status);
        $this->assertNull($scan->started_at);
    }

    public function test_the_job_carries_an_id_and_nothing_else(): void
    {
        Queue::fake();

        $this->customer();
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        Queue::assertPushed(ProcessDocumentScan::class, function ($job) {
            // A job payload sits in the queue store until a worker picks it
            // up. Putting a document's bytes in one would be keeping a copy
            // of a customer's file somewhere nobody accounted for.
            $serialised = serialize($job);

            $this->assertStringNotContainsString('%PDF', $serialised);
            $this->assertStringNotContainsString('storage_key', $serialised);

            return $job->scanId === DocumentScan::sole()->getKey();
        });
    }

    public function test_three_taps_still_queue_one_reading(): void
    {
        Queue::fake();

        $this->customer();
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        // The first is still in flight, so the next two are answered from
        // it rather than queueing more provider calls.
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        Queue::assertPushed(ProcessDocumentScan::class, 1);
        $this->assertSame(1, DocumentScan::count());
    }

    // --- The worker re-checks everything -------------------------------------

    public function test_a_worker_will_not_read_a_document_deleted_since(): void
    {
        Queue::fake();

        $this->customer();
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        // The customer deletes it before the worker gets to it.
        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

        $this->runWorker();

        $scan = DocumentScan::sole();
        $this->assertSame(ScanStatus::Failed->value, $scan->status);
        $this->assertSame('document_missing', $scan->failure_reason);
        $this->assertDatabaseCount('document_scan_fields', 0);
    }

    public function test_a_worker_will_not_read_a_file_replaced_since(): void
    {
        Queue::fake();

        $this->customer();
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        // Different bytes are now behind the same document.
        $document = Document::find($id);
        $document->disk()->put($document->storage_key, '%PDF-1.4 different');

        $this->runWorker();

        /*
         * The customer asked for one file to be read and a different one is
         * now stored. Reading it would answer a question they did not ask.
         */
        $scan = DocumentScan::sole();
        $this->assertSame(ScanStatus::Failed->value, $scan->status);
        $this->assertSame('document_changed', $scan->failure_reason);
    }

    public function test_a_worker_will_not_send_a_document_consent_was_withdrawn_for(): void
    {
        Queue::fake();

        $user = $this->customer();
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        // The customer changes their mind while it sits in the queue.
        $this->postJson('/api/v1/consents/document_processing/withdraw')
            ->assertSuccessful();

        $this->runWorker();

        // Nothing was sent, and nothing was kept. The withdrawal already
        // removed the queued scan; the worker finding it gone is the
        // second half of the same guarantee.
        $this->assertSame(0, $user->documentScans()->count());
        $this->assertDatabaseCount('document_scan_fields', 0);
    }

    public function test_a_job_for_a_scan_that_no_longer_exists_does_nothing(): void
    {
        Queue::fake();

        $this->customer();
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertAccepted();

        $scanId = DocumentScan::sole()->getKey();
        DocumentScan::whereKey($scanId)->delete();

        // A worker picking up a job whose subject is gone has nothing to do
        // and nothing to report — and must not raise.
        (new ProcessDocumentScan($scanId))
            ->handle(app(DocumentUnderstandingService::class));

        $this->assertDatabaseCount('document_scans', 0);
    }

    // --- Under the sync connection the customer still sees the result --------

    public function test_on_the_sync_connection_the_reading_completes_in_the_request(): void
    {
        // No Queue::fake here: the sync connection runs the worker inline,
        // which is what development and the test suite use.
        $this->customer();
        $id = $this->upload();

        $data = $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $data['status']);
        $this->assertNotNull($data['extraction']);
    }

    /** Runs every queued job, the way a worker would. */
    private function runWorker(): void
    {
        $understanding = app(DocumentUnderstandingService::class);

        foreach (DocumentScan::pluck('id') as $scanId) {
            (new ProcessDocumentScan($scanId))->handle($understanding);
        }
    }
}
