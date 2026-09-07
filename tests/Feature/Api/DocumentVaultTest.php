<?php

namespace Tests\Feature\Api;

use App\Domain\Vault\UploadRules;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentVaultTest extends TestCase
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

    /** A real, if tiny, PDF: enough bytes for the server to detect the type. */
    private function pdf(string $name = 'statement.pdf'): UploadedFile
    {
        $body = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, $body);

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function upload(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/documents', array_merge([
            'file' => $this->pdf(),
            'type' => 'bank',
        ], $overrides));
    }

    // --- Listing and metadata -----------------------------------------------

    public function test_a_new_vault_is_empty(): void
    {
        $this->customer();

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_it_stores_a_document_and_describes_it(): void
    {
        $this->customer();

        $data = $this->upload(['name' => 'March statement'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('March statement', $data['name']);
        $this->assertSame('bank', $data['type']);
        $this->assertSame('Bank', $data['type_label']);
        $this->assertSame('statement.pdf', $data['original_filename']);
        $this->assertSame('application/pdf', $data['mime_type']);
        $this->assertGreaterThan(0, $data['size_bytes']);
        $this->assertSame('uploaded', $data['status']);
        $this->assertSame('Stored', $data['status_label']);
        $this->assertNotNull($data['uploaded_at']);
    }

    public function test_a_stored_document_is_not_a_verified_one(): void
    {
        $this->customer();
        $data = $this->upload()->assertCreated()->json('data');

        // Uploading proves the bytes arrived and nothing else.
        $this->assertFalse($data['is_verified']);
        $this->assertFalse($data['is_shared']);
        $this->assertSame('uploaded', $data['status']);
        $this->assertNotContains($data['status'], ['verified', 'processing', 'rejected']);
    }

    public function test_the_name_falls_back_to_the_file(): void
    {
        $this->customer();

        $this->assertSame(
            'statement',
            $this->upload()->assertCreated()->json('data.name'),
        );
    }

    public function test_the_list_is_newest_first(): void
    {
        $this->customer();

        $first = $this->upload(['name' => 'One'])->json('data.id');
        $second = $this->upload(['name' => 'Two'])->json('data.id');

        $this->assertSame(
            [$second, $first],
            collect($this->getJson('/api/v1/documents')->json('data'))->pluck('id')->all(),
        );
    }

    public function test_the_limits_are_published_so_the_app_can_say_them(): void
    {
        $this->customer();

        $data = $this->getJson('/api/v1/documents/limits')->assertOk()->json('data');

        $this->assertSame(UploadRules::MAX_BYTES, $data['max_bytes']);
        $this->assertContains('application/pdf', $data['mime_types']);
        $this->assertCount(6, $data['types']);
    }

    // --- What the payload must never contain --------------------------------

    public function test_the_response_never_exposes_where_the_file_lives(): void
    {
        $this->customer();
        $created = $this->upload()->assertCreated();
        $id = $created->json('data.id');

        $document = Document::find($id);
        $this->assertNotEmpty($document->storage_key);

        foreach ([
            $created,
            $this->getJson('/api/v1/documents'),
            $this->getJson('/api/v1/documents/'.$id),
        ] as $response) {
            $body = $response->getContent();

            $this->assertStringNotContainsString($document->storage_key, $body);
            $this->assertStringNotContainsString('storage_key', $body);
            $this->assertStringNotContainsString('storage_disk', $body);
            $this->assertStringNotContainsString('storage/app', $body);
            $this->assertStringNotContainsString(storage_path(), $body);
        }
    }

    public function test_the_model_hides_the_storage_location_by_default(): void
    {
        $this->customer();
        $this->upload();

        // Even a plain toArray() cannot publish it.
        $array = Document::sole()->toArray();

        $this->assertArrayNotHasKey('storage_key', $array);
        $this->assertArrayNotHasKey('storage_disk', $array);
    }

    // --- Upload validation --------------------------------------------------

    public function test_it_refuses_a_file_type_it_does_not_take(): void
    {
        $this->customer();

        $this->upload(['file' => UploadedFile::fake()->create('macro.docx', 20)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
        Storage::disk(DocumentVaultService::DISK)->assertDirectoryEmpty('');
    }

    public function test_it_refuses_an_executable_however_it_is_labelled(): void
    {
        $this->customer();

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.php';
        file_put_contents($path, "<?php echo 'no'; ?>\n");

        // Claiming to be a PDF, named as a PDF, and still a PHP file.
        $this->upload([
            'file' => new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true),
        ])->assertStatus(422);

        $this->assertSame(0, Document::count());
    }

    public function test_it_refuses_an_extension_that_contradicts_the_bytes(): void
    {
        $this->customer();

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.png';
        file_put_contents($path, "%PDF-1.4\ntrailer<</Root 1 0 R>>\n%%EOF\n");

        $this->upload([
            'file' => new UploadedFile($path, 'card.png', 'image/png', null, true),
        ])->assertStatus(422);

        $this->assertSame(0, Document::count());
    }

    public function test_it_refuses_an_oversized_file(): void
    {
        $this->customer();

        $this->upload([
            'file' => UploadedFile::fake()->create(
                'huge.pdf',
                (int) (UploadRules::MAX_BYTES / 1024) + 512,
                'application/pdf',
            ),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
    }

    public function test_it_requires_a_file_and_a_known_type(): void
    {
        $this->customer();

        $this->postJson('/api/v1/documents', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file', 'type']);

        $this->upload(['type' => 'passport_photo_of_my_dog'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    // --- Storage keys -------------------------------------------------------

    public function test_the_storage_key_is_the_servers_and_not_the_filename(): void
    {
        $user = $this->customer();
        $this->upload(['file' => $this->pdf('My Statement (final).pdf')])->assertCreated();

        $document = Document::sole();

        $this->assertMatchesRegularExpression(
            '#^u'.$user->id.'/[0-9A-Z]{26}\.pdf$#',
            $document->storage_key,
        );
        $this->assertStringNotContainsString('My Statement', $document->storage_key);
        Storage::disk(DocumentVaultService::DISK)->assertExists($document->storage_key);
    }

    public function test_a_traversing_filename_cannot_escape_the_vault(): void
    {
        $user = $this->customer();

        $this->upload([
            'file' => $this->pdf('../../../../etc/passwd.pdf'),
        ])->assertCreated();

        $document = Document::sole();

        $this->assertStringNotContainsString('..', $document->storage_key);
        $this->assertStringNotContainsString('/etc/', $document->storage_key);
        $this->assertStringStartsWith('u'.$user->id.'/', $document->storage_key);
        // The name is kept for display, with the path stripped out of it.
        $this->assertSame('passwd.pdf', $document->original_filename);
    }

    public function test_two_uploads_of_the_same_file_do_not_collide(): void
    {
        $this->customer();

        $first = $this->upload()->json('data.id');
        $second = $this->upload()->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertNotSame(
            Document::find($first)->storage_key,
            Document::find($second)->storage_key,
        );
        $this->assertCount(2, Storage::disk(DocumentVaultService::DISK)->allFiles());
    }

    // --- Download -----------------------------------------------------------

    public function test_the_owner_can_download_their_document(): void
    {
        $this->customer();
        $id = $this->upload(['name' => 'March statement'])->json('data.id');

        $response = $this->get('/api/v1/documents/'.$id.'/download');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment; filename="March statement.pdf"',
            $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertStringContainsString('%PDF', $response->streamedContent());
    }

    public function test_it_can_be_viewed_inline_when_asked(): void
    {
        $this->customer();
        $id = $this->upload()->json('data.id');

        $response = $this->get('/api/v1/documents/'.$id.'/download?inline=1');

        $this->assertStringStartsWith(
            'inline;',
            $response->headers->get('content-disposition'),
        );
    }

    public function test_a_document_whose_file_has_gone_is_a_clean_404(): void
    {
        $this->customer();
        $id = $this->upload()->json('data.id');

        Storage::disk(DocumentVaultService::DISK)
            ->delete(Document::find($id)->storage_key);

        $this->getJson('/api/v1/documents/'.$id.'/download')
            ->assertNotFound()
            ->assertJsonPath('message', 'That document is no longer stored.');
    }

    // --- Deletion -----------------------------------------------------------

    public function test_deleting_removes_the_file_and_keeps_the_record(): void
    {
        $this->customer();
        $id = $this->upload()->json('data.id');
        $key = Document::find($id)->storage_key;

        $this->deleteJson('/api/v1/documents/'.$id)->assertNoContent();

        // The bytes are gone.
        Storage::disk(DocumentVaultService::DISK)->assertMissing($key);

        // The vault no longer lists it, and cannot fetch it.
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/documents/'.$id)->assertNotFound();
        $this->getJson('/api/v1/documents/'.$id.'/download')->assertNotFound();

        // But the record that it existed and was removed survives.
        $this->assertNotNull(Document::withTrashed()->find($id)->deleted_at);
    }

    public function test_deleting_twice_is_a_404_not_a_crash(): void
    {
        $this->customer();
        $id = $this->upload()->json('data.id');

        $this->deleteJson('/api/v1/documents/'.$id)->assertNoContent();
        $this->deleteJson('/api/v1/documents/'.$id)->assertNotFound();
    }

    // --- Ownership ----------------------------------------------------------

    public function test_the_whole_vault_requires_a_session(): void
    {
        $this->customer();
        $id = $this->upload()->json('data.id');
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/documents')->assertStatus(401);
        $this->getJson('/api/v1/documents/limits')->assertStatus(401);
        $this->postJson('/api/v1/documents', [])->assertStatus(401);
        $this->getJson('/api/v1/documents/'.$id)->assertStatus(401);
        $this->getJson('/api/v1/documents/'.$id.'/download')->assertStatus(401);
        $this->deleteJson('/api/v1/documents/'.$id)->assertStatus(401);
    }

    public function test_one_customers_document_cannot_be_read_by_another(): void
    {
        $victim = $this->customer();
        $theirs = $this->upload(['name' => 'Private statement'])->json('data.id');

        $this->customer();

        $missing = $this->getJson('/api/v1/documents/999999');
        $notMine = $this->getJson('/api/v1/documents/'.$theirs);

        $notMine->assertNotFound();
        $missing->assertNotFound();
        // Indistinguishable: "not yours" must not read differently from
        // "does not exist".
        $this->assertSame($missing->json('message'), $notMine->json('message'));

        // And the bytes are just as unreachable.
        $this->getJson('/api/v1/documents/'.$theirs.'/download')->assertNotFound();

        $this->assertSame($victim->id, Document::find($theirs)->user_id);
    }

    public function test_one_customer_cannot_delete_anothers_document(): void
    {
        $this->customer();
        $theirs = $this->upload()->json('data.id');
        $key = Document::find($theirs)->storage_key;

        $this->customer();

        $this->deleteJson('/api/v1/documents/'.$theirs)->assertNotFound();

        $this->assertNull(Document::find($theirs)->deleted_at);
        Storage::disk(DocumentVaultService::DISK)->assertExists($key);
    }

    public function test_a_user_id_in_the_payload_never_establishes_ownership(): void
    {
        $victim = $this->customer();
        $me = $this->customer();

        $id = $this->upload([
            'user_id' => $victim->id,
            'customer_id' => $victim->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($me->id, Document::find($id)->user_id);
        $this->assertSame(0, $victim->documents()->count());
    }

    public function test_a_customer_only_ever_lists_their_own(): void
    {
        $this->customer();
        $this->upload(['name' => 'Theirs']);

        $this->customer();
        $this->upload(['name' => 'Mine']);

        $listed = $this->getJson('/api/v1/documents')->json('data');

        $this->assertCount(1, $listed);
        $this->assertSame('Mine', $listed[0]['name']);
    }
}
