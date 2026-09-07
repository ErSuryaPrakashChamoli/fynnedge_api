<?php

namespace Tests\Feature\Vault;

use App\Domain\Vault\ContentDigest;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What binding a record to a file costs, and what it still detects.
 *
 * Three modules bind a stored answer to the bytes it described — a scan, a
 * KYC result, a document confirmation — and each asks "is this still the
 * same file?" on a read path. Answering that by reading the file in full
 * made a five-document application checklist read 45 MB per request. These
 * tests fix what the cheaper answer is allowed to be: the same answer for
 * every ordinary change, and an explicitly weaker one for a rewrite that
 * disguises itself as no change at all.
 */
class ContentDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);

        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);
    }

    private function upload(string $contents = "%PDF-1.4\n%%EOF\n"): Document
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, $contents);

        $id = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'income.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        return Document::findOrFail($id);
    }

    private function digests(): ContentDigest
    {
        return app(ContentDigest::class);
    }

    /** The bytes on disk, whatever the record says. */
    private function actualDigest(Document $document): string
    {
        return hash('sha256', $document->disk()->get($document->storage_key));
    }

    public function test_it_reports_the_digest_of_the_stored_bytes(): void
    {
        $document = $this->upload();

        $this->assertSame(
            $this->actualDigest($document),
            $this->digests()->of($document),
        );
    }

    public function test_a_document_with_no_file_has_no_digest(): void
    {
        $document = $this->upload();
        $document->disk()->delete($document->storage_key);

        // Not the digest of an empty string, which is a real sha256 value
        // and would compare equal to another empty file.
        $this->assertNull($this->digests()->of($document));
        $this->assertNull($this->digests()->of(null));
    }

    public function test_nothing_matches_a_record_that_never_recorded_a_digest(): void
    {
        $document = $this->upload();

        $this->assertFalse($this->digests()->matches($document, null));
    }

    public function test_a_changed_file_is_detected_even_once_the_digest_is_known(): void
    {
        $document = $this->upload();
        $recorded = $this->digests()->of($document);

        // Warm: the answer for this file is now remembered.
        $this->assertTrue($this->digests()->matches($document, $recorded));

        $document->disk()->put($document->storage_key, '%PDF-1.4 something else entirely');

        $this->assertFalse(
            $this->digests()->matches($document, $recorded),
            'A remembered digest must not outlive the file it described.',
        );
        $this->assertSame($this->actualDigest($document), $this->digests()->of($document));
    }

    public function test_a_same_size_rewrite_is_detected(): void
    {
        $document = $this->upload("%PDF-1.4\nAAAA\n%%EOF\n");
        $recorded = $this->digests()->of($document);

        // Identical length, different contents, and a new timestamp.
        $document->disk()->put($document->storage_key, "%PDF-1.4\nBBBB\n%%EOF\n");
        touch($document->disk()->path($document->storage_key), time() + 5);

        $this->assertFalse($this->digests()->matches($document, $recorded));
    }

    /**
     * The one case the cheap answer gets wrong, stated plainly.
     *
     * A rewrite that keeps both the byte count and the modification time is
     * indistinguishable from no rewrite without reading the file, so the
     * remembered digest stands. This is deliberate and is the whole price
     * of not reading every attached document on every request; it takes a
     * process with write access to the vault and an effort to hide, and
     * such a process can equally rewrite the digest FynnEdge compares
     * against. What still catches it is anything that actually reads the
     * bytes — a scan, a verification, a transfer out — each of which
     * hashes what it read rather than trusting this.
     *
     * If that trade ever stops being acceptable, this test is the one that
     * changes, and it says so here rather than in a comment nobody reads.
     */
    public function test_a_rewrite_that_hides_its_size_and_timestamp_is_not_detected_on_a_read_path(): void
    {
        $document = $this->upload("%PDF-1.4\nAAAA\n%%EOF\n");
        $recorded = $this->digests()->of($document);

        $file = $document->disk()->path($document->storage_key);
        $when = filemtime($file);

        $document->disk()->put($document->storage_key, "%PDF-1.4\nBBBB\n%%EOF\n");
        touch($file, $when);

        $this->assertTrue(
            $this->digests()->matches($document, $recorded),
            'Documented limitation: size and timestamp are what a read path checks.',
        );

        // And the bytes really did change, so this is a limitation and not
        // a test that proves nothing.
        $this->assertNotSame($recorded, $this->actualDigest($document));
    }

    public function test_two_documents_with_identical_contents_do_not_share_an_answer_by_accident(): void
    {
        $a = $this->upload("%PDF-1.4\nSAME\n%%EOF\n");
        $b = $this->upload("%PDF-1.4\nSAME\n%%EOF\n");

        $this->assertSame($this->digests()->of($a), $this->digests()->of($b));

        $b->disk()->put($b->storage_key, '%PDF-1.4 diverged now');

        $this->assertSame($this->actualDigest($a), $this->digests()->of($a));
        $this->assertSame($this->actualDigest($b), $this->digests()->of($b));
    }
}
