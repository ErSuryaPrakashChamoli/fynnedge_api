<?php

namespace Tests\Feature\Scan;

use App\Domain\Consent\ConsentType;
use App\Domain\Scan\DocumentFamily;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Models\Document;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose document, whose decision, and what a request cannot decide.
 */
class ScanSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.scan.driver' => 'fixture']);
    }

    private function customer(string $name = 'Customer'): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => $name]);
        $user->financialProfile->update([
            'monthly_income' => 60000,
            'monthly_expenses' => 20000,
            'existing_emi' => 5000,
            'savings' => 100000,
        ]);

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function consent(User $user): void
    {
        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );
    }

    private function upload(string $type = 'income'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    // --- Ownership ----------------------------------------------------------

    public function test_another_customers_document_cannot_be_scanned_or_read(): void
    {
        $owner = $this->customer('Owner');
        $this->as($owner);
        $this->consent($owner);
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        $intruder = $this->customer('Intruder');
        $this->as($intruder);
        $this->consent($intruder);

        // The same answer as a document that never existed.
        foreach ([
            ['get', "/api/v1/documents/{$id}/scan", []],
            ['postJson', "/api/v1/documents/{$id}/scan", []],
            ['postJson', "/api/v1/documents/{$id}/scan/review", [
                'field' => 'net_income', 'decision' => 'confirmed',
            ]],
        ] as [$verb, $uri, $payload]) {
            $response = $verb === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri, $payload);

            $this->assertSame(404, $response->getStatusCode(), $uri);
            $this->assertStringNotContainsString('75000', $response->getContent());
        }

        $this->assertSame(1, DocumentScan::count());
    }

    public function test_a_forged_identity_in_the_payload_reaches_nothing(): void
    {
        $owner = $this->customer('Owner');
        $this->as($owner);
        $this->consent($owner);
        $ownerDocument = $this->upload();

        $intruder = $this->customer('Intruder');
        $this->as($intruder);
        $this->consent($intruder);
        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        // Every identity a client might try to smuggle in.
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
            'apply_to_profile' => true,
            'document_id' => $ownerDocument,
            'user_id' => $owner->id,
            'customer_id' => $owner->id,
            'confirmed_by' => $owner->id,
            'actor_id' => $owner->id,
        ])->assertOk();

        // The intruder's own profile changed. The owner's did not.
        $this->assertSame('75000.00', (string) $intruder->financialProfile->fresh()->monthly_income);
        $this->assertSame('60000.00', (string) $owner->financialProfile->fresh()->monthly_income);

        // And the decision is recorded against whoever held the token.
        $this->assertSame(
            $intruder->id,
            DocumentScan::where('user_id', $intruder->id)->first()->reviews()->first()->user_id,
        );
    }

    public function test_every_scan_route_requires_a_session(): void
    {
        // No acting user at all. An id cannot be reached without a token,
        // whether or not that id happens to exist.
        foreach ([
            ['getJson', '/api/v1/documents/1/scan', []],
            ['postJson', '/api/v1/documents/1/scan', []],
            ['postJson', '/api/v1/documents/1/scan/review', [
                'field' => 'net_income', 'decision' => 'confirmed',
            ]],
        ] as [$verb, $uri, $payload]) {
            $response = $verb === 'getJson'
                ? $this->getJson($uri)
                : $this->postJson($uri, $payload);

            $this->assertSame(401, $response->getStatusCode(), $uri);
        }
    }

    // --- A client cannot author an extraction -------------------------------

    public function test_a_customer_cannot_supply_their_own_extraction(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan", [
            // Everything a client might try to author.
            'extraction' => [['key' => 'net_income', 'value' => '900000']],
            'fields' => [['key' => 'net_income', 'value' => '900000']],
            'confidence' => 1,
            'status' => 'completed',
            'provider' => 'real-provider',
            'is_sample' => false,
            'schema_version' => 'income_v99',
        ])->assertOk();

        $scan = DocumentScan::first();

        // The provider's answer, not the customer's.
        $this->assertSame('fixture', $scan->provider);
        $this->assertTrue($scan->is_sample);
        $this->assertSame('income_v1', $scan->schema_version);

        $net = $scan->fields()->where('key', 'net_income')->first();
        $this->assertSame('75000.00', $net->value);
        $this->assertSame(7500000, $net->amount_paise);
    }

    public function test_a_request_cannot_choose_the_provider_or_the_scenario(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        foreach ([
            '?provider=none',
            '?driver=none',
            '?fixture_scenario=good',
            '?scenario=good',
            '?is_sample=0',
            '?skip_consent=1',
            '?force_scan=1',
            '?user_id=1',
        ] as $query) {
            $response = $this->postJson("/api/v1/documents/{$id}/scan{$query}");
            $this->assertContains($response->getStatusCode(), [200, 202]);

            $scan = DocumentScan::latest('id')->first();

            // Sample stays sample, whatever the client asks.
            $this->assertTrue($scan->is_sample, $query);
            $this->assertSame('fixture', $scan->provider, $query);
        }
    }

    public function test_the_sample_flag_survives_all_the_way_to_the_response(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->assertJsonPath('data.is_sample', true);

        $this->getJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->assertJsonPath('data.is_sample', true);
    }

    // --- Consent cannot be walked around ------------------------------------

    public function test_consent_is_rechecked_before_the_document_moves(): void
    {
        $user = $this->customer();
        $this->as($user);
        $id = $this->upload();

        // Never granted: the provider is never reached.
        $spy = new class implements DocumentUnderstandingProvider
        {
            public int $calls = 0;

            public function isAvailable(): bool
            {
                return true;
            }

            public function name(): ?string
            {
                return 'spy';
            }

            public function isSample(): bool
            {
                return true;
            }

            public function supports(DocumentFamily $family): bool
            {
                return true;
            }

            public function process(
                Document $document,
                string $bytes,
                DocumentFamily $family,
            ): array {
                $this->calls++;

                return [];
            }
        };

        $this->app->instance(DocumentUnderstandingProvider::class, $spy);

        $this->postJson("/api/v1/documents/{$id}/scan")->assertForbidden();

        $this->assertSame(
            0,
            $spy->calls,
            'a document was sent to a provider without consent',
        );
    }

    // --- Cost control -------------------------------------------------------

    public function test_three_taps_are_one_reading(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        }

        // The reading already held describes these exact bytes, so asking
        // the provider again would spend money to learn nothing.
        $this->assertSame(1, DocumentScan::count());
    }

    public function test_a_deliberate_rescan_is_a_new_attempt(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->postJson("/api/v1/documents/{$id}/scan", ['force' => true])->assertOk();

        $this->assertSame(2, DocumentScan::count());

        // The earlier reading survives the new one intact. Rescanning adds
        // an attempt; it does not rewrite the evidence of the last.
        $first = DocumentScan::orderBy('id')->first();
        $this->assertSame(5, $first->fields()->count());
        $this->assertSame(
            '75000.00',
            $first->fields()->where('key', 'net_income')->first()->value,
        );
    }

    public function test_the_scan_limiter_is_stricter_than_the_api(): void
    {
        config(['fynnedge.scan.scans_per_hour' => 2]);

        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan", ['force' => true])->assertOk();
        $this->postJson("/api/v1/documents/{$id}/scan", ['force' => true])->assertOk();

        $this->postJson("/api/v1/documents/{$id}/scan", ['force' => true])
            ->assertStatus(429);

        // Reading the state is free and stays available: it asks nobody.
        $this->getJson("/api/v1/documents/{$id}/scan")->assertOk();
    }

    // --- Documents that go away ---------------------------------------------

    public function test_a_deleted_document_can_no_longer_be_scanned_or_read(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();

        $this->getJson("/api/v1/documents/{$id}/scan")->assertNotFound();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertNotFound();
        $this->postJson("/api/v1/documents/{$id}/scan/review", [
            'field' => 'net_income',
            'decision' => 'confirmed',
        ])->assertNotFound();
    }

    public function test_a_reading_never_describes_a_file_it_was_not_taken_from(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->getJson("/api/v1/documents/{$id}/scan")
            ->assertJsonPath('data.is_stale', false);

        // The bytes behind the document change.
        $document = Document::find($id);
        $document->disk()->put($document->storage_key, '%PDF-1.4 different bytes');

        // The extraction described the previous file, and says so rather
        // than being presented as a reading of this one.
        $this->getJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->assertJsonPath('data.is_stale', true);

        // And a fresh scan is allowed without needing force, because the
        // held reading no longer describes what is stored.
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->assertSame(2, DocumentScan::count());
    }

    public function test_a_missing_file_invents_nothing_about_its_contents(): void
    {
        $user = $this->customer();
        $this->as($user);
        $this->consent($user);
        $id = $this->upload();

        $document = Document::find($id);
        $document->disk()->delete($document->storage_key);

        $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'document_missing');

        $this->assertDatabaseCount('document_scans', 0);
    }
}
