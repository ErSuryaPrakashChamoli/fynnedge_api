<?php

namespace Tests\Feature\Api;

use App\Domain\Vault\UploadRules;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What FynnVault does with a file it is handed, and a name it is told.
 *
 * A vault holds a customer's payslips and identity documents. The interesting
 * question is not whether an ordinary PDF works; it is what happens when the
 * filename is an attack, the extension lies about the contents, or someone
 * asks for a file that is not theirs.
 */
class VaultHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);

        $this->user = User::factory()->create();
        $this->user->ensureProfiles();
        Sanctum::actingAs($this->user);
    }

    private function file(string $name, string $bytes, string $claimedMime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $claimedMime, null, true);
    }

    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01"
        ."\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

    // --- Filenames -------------------------------------------------------

    #[DataProvider('hostileNames')]
    public function test_a_hostile_filename_never_becomes_a_path(
        string $filename,
    ): void {
        $response = $this->postJson('/api/v1/documents', [
            'file' => $this->file($filename.'.pdf', self::PDF, 'application/pdf'),
            'type' => 'income',
        ]);

        $response->assertCreated();
        $document = Document::first();

        // The key is the server's, built from an id and the detected type.
        $this->assertMatchesRegularExpression(
            '#^u\d+/[0-9A-Z]{26}\.pdf$#',
            $document->storage_key,
            "A filename reached the storage path: {$filename}",
        );

        // The name kept for display carries no traversal and no control
        // characters, so it is safe in a log and safe on a screen.
        $this->assertStringNotContainsString('..', $document->original_filename);
        $this->assertStringNotContainsString('/', $document->original_filename);
        $this->assertStringNotContainsString('\\', $document->original_filename);
        $this->assertLessThanOrEqual(250, strlen($document->original_filename));

        $document->delete();
    }

    public static function hostileNames(): array
    {
        return [
            'parent traversal' => ['../../secret'],
            'deep traversal' => ['../../../etc/passwd'],
            'windows traversal' => ['..\\..\\windows\\system32\\config'],
            'attribute break' => ['" onload="alert(1)'],
            'script tag' => ['<script>alert(1)</script>'],
            'null byte' => ["payslip\x00.php"],
            'newline' => ["payslip\nSet-Cookie: a=b"],
            'very long' => [str_repeat('a', 400)],
            'unicode' => ['वेतन-पर्ची-२०२६'],
            'leading dots' => ['.....hidden'],
            'only dots' => ['...'],
        ];
    }

    public function test_the_download_name_is_rebuilt_from_what_we_control(): void
    {
        $id = $this->postJson('/api/v1/documents', [
            'file' => $this->file('" onload="alert(1).pdf', self::PDF, 'application/pdf'),
            'type' => 'income',
            'display_name' => '" onload="alert(1)',
        ])->assertCreated()->json('data.id');

        $response = $this->get("/api/v1/documents/{$id}/download");
        $response->assertOk();

        $disposition = $response->headers->get('Content-Disposition');

        // One pair of quotes, and nothing left inside them that could close
        // them or start an attribute. The letters survive and are harmless;
        // the punctuation that made them an attack does not.
        $this->assertSame(2, substr_count($disposition, '"'), $disposition);
        foreach (['<', '>', '=', ';', "\r", "\n", '\\'] as $dangerous) {
            $this->assertStringNotContainsString(
                $dangerous,
                substr($disposition, strpos($disposition, '"')),
                "Content-Disposition filename kept {$dangerous}",
            );
        }
        $this->assertStringEndsWith('.pdf"', $disposition);

        // And the browser is told not to guess, nor to keep it.
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    // --- Contents versus claims -----------------------------------------

    public function test_a_file_whose_extension_lies_is_refused(): void
    {
        // A PDF wearing a .png extension.
        $this->postJson('/api/v1/documents', [
            'file' => $this->file('payslip.png', self::PDF, 'image/png'),
            'type' => 'income',
        ])->assertStatus(422);

        // And a PNG wearing .pdf.
        $this->postJson('/api/v1/documents', [
            'file' => $this->file('payslip.pdf', self::PNG, 'application/pdf'),
            'type' => 'income',
        ])->assertStatus(422);

        $this->assertSame(0, Document::count());
    }

    public function test_executable_content_is_refused_whatever_it_is_called(): void
    {
        foreach ([
            ['shell.pdf', "<?php system(\$_GET['c']); ?>\n"],
            ['shell.php', "<?php system(\$_GET['c']); ?>\n"],
            ['script.pdf', "#!/bin/sh\nrm -rf /\n"],
            ['macro.pdf', "MZ\x90\x00\x03"],
        ] as [$name, $bytes]) {
            $this->postJson('/api/v1/documents', [
                'file' => $this->file($name, $bytes, 'application/pdf'),
                'type' => 'income',
            ])->assertStatus(422);
        }

        $this->assertSame(0, Document::count());
        $this->assertEmpty(Storage::disk(DocumentVaultService::DISK)->allFiles());
    }

    public function test_the_size_limit_is_enforced_by_the_server(): void
    {
        $tooBig = str_repeat('a', UploadRules::MAX_BYTES + 1024);

        $this->postJson('/api/v1/documents', [
            'file' => $this->file('big.pdf', self::PDF.$tooBig, 'application/pdf'),
            'type' => 'income',
        ])->assertStatus(422);

        $this->assertSame(0, Document::count());
    }

    // --- Storage ----------------------------------------------------------

    public function test_the_disk_is_private_and_has_no_public_url(): void
    {
        $disk = config('filesystems.disks.'.DocumentVaultService::DISK);

        $this->assertSame('local', $disk['driver']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertNotSame('public', $disk['visibility'] ?? null);
        $this->assertStringNotContainsString(
            public_path(),
            $disk['root'],
            'FynnVault writes inside the web root',
        );
    }

    public function test_deleting_removes_the_bytes(): void
    {
        $id = $this->postJson('/api/v1/documents', [
            'file' => $this->file('payslip.pdf', self::PDF, 'application/pdf'),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $key = Document::find($id)->storage_key;
        Storage::disk(DocumentVaultService::DISK)->assertExists($key);

        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

        Storage::disk(DocumentVaultService::DISK)->assertMissing($key);
    }

    public function test_the_vault_never_claims_a_document_is_verified(): void
    {
        $this->postJson('/api/v1/documents', [
            'file' => $this->file('payslip.pdf', self::PDF, 'application/pdf'),
            'type' => 'income',
        ])->assertCreated();

        $body = $this->getJson('/api/v1/documents')->assertOk()->getContent();
        $data = json_decode($body, true)['data'][0];

        $this->assertFalse($data['is_verified']);
        $this->assertSame('uploaded', $data['status']);
        $this->assertStringNotContainsStringIgnoringCase('kyc', $body);
        $this->assertStringNotContainsStringIgnoringCase('submitted to', $body);
    }
}
