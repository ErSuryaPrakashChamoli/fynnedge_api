<?php

namespace Tests\Feature\ApplicationDocuments;

use App\Models\ApplicationDocument;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose application, whose document, and what a request cannot decide.
 */
class DocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
    }

    private function customer(string $name): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => $name, 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function application(): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    private function upload(string $type = 'income'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, "{$type}.pdf", 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    // --- Ownership of the application ---------------------------------------

    public function test_another_customers_application_is_not_found(): void
    {
        $owner = $this->customer('Owner Person');
        $this->as($owner);
        $application = $this->application();
        $document = $this->upload();
        $this->postJson("/api/v1/applications/{$application}/documents", [
            'requirement_code' => 'income',
            'document_id' => $document,
        ])->assertOk();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);

        foreach ([
            ['getJson', "/api/v1/applications/{$application}/documents", []],
            ['postJson', "/api/v1/applications/{$application}/documents", [
                'requirement_code' => 'income',
                'document_id' => $this->upload(),
            ]],
            ['deleteJson', "/api/v1/applications/{$application}/documents/income", []],
            ['postJson', "/api/v1/applications/{$application}/documents/income/review", [
                'decision' => 'accepted',
            ]],
        ] as [$verb, $uri, $payload]) {
            $response = match ($verb) {
                'getJson' => $this->getJson($uri),
                'deleteJson' => $this->deleteJson($uri),
                default => $this->postJson($uri, $payload),
            };

            $this->assertSame(404, $response->getStatusCode(), "{$verb} {$uri}");

            // And nothing about the owner's choice comes back.
            $this->assertStringNotContainsString('income.pdf', $response->getContent());
        }

        // The owner's slot is untouched by any of it.
        $this->assertSame(1, ApplicationDocument::count());
        $this->assertSame('available', ApplicationDocument::first()->status);
    }

    // --- Ownership of the document ------------------------------------------

    public function test_another_customers_document_cannot_be_attached(): void
    {
        $owner = $this->customer('Owner Person');
        $this->as($owner);
        $ownersDocument = $this->upload();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);
        $application = $this->application();

        // Not found rather than refused: an id the customer does not own
        // is indistinguishable from one that does not exist.
        $this->postJson("/api/v1/applications/{$application}/documents", [
            'requirement_code' => 'income',
            'document_id' => $ownersDocument,
        ])->assertStatus(404)
            ->assertJsonPath('reason', 'document_not_found');

        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_a_forged_identity_in_the_payload_reaches_nothing(): void
    {
        $owner = $this->customer('Owner Person');
        $this->as($owner);
        $ownersApplication = $this->application();

        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);
        $application = $this->application();
        $document = $this->upload();

        $this->postJson("/api/v1/applications/{$application}/documents", [
            'requirement_code' => 'income',
            'document_id' => $document,

            // Everything a client might try to smuggle in.
            'user_id' => $owner->id,
            'customer_id' => $owner->id,
            'application_id' => $ownersApplication,
            'status' => 'ready',
            'reviewed_at' => now()->toIso8601String(),
        ])->assertOk();

        $choice = ApplicationDocument::sole();

        // Owned by the token holder, on their own application, and
        // unconfirmed — because they have not confirmed it.
        $this->assertSame($intruder->id, $choice->user_id);
        $this->assertSame((int) $application, $choice->loan_application_id);
        $this->assertSame('available', $choice->status);
        $this->assertNull($choice->reviewed_at);
    }

    public function test_readiness_cannot_be_forged(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $application = $this->application();

        // A status in the body reaches nothing, and neither does one in
        // the review call beyond the two decisions it accepts.
        $this->postJson("/api/v1/applications/{$application}/documents/income/review", [
            'decision' => 'ready',
        ])->assertStatus(422);

        $this->postJson("/api/v1/applications/{$application}/documents/income/review", [
            'decision' => 'accepted',
            'status' => 'ready',
        ])->assertStatus(409);

        $this->assertFalse(
            $this->getJson("/api/v1/applications/{$application}/documents")
                ->json('data.ready'),
        );
    }

    public function test_every_route_requires_a_session(): void
    {
        foreach ([
            ['getJson', '/api/v1/applications/1/documents', []],
            ['postJson', '/api/v1/applications/1/documents', [
                'requirement_code' => 'income', 'document_id' => '1',
            ]],
            ['deleteJson', '/api/v1/applications/1/documents/income', []],
            ['postJson', '/api/v1/applications/1/documents/income/review', [
                'decision' => 'accepted',
            ]],
        ] as [$verb, $uri, $payload]) {
            $response = match ($verb) {
                'getJson' => $this->getJson($uri),
                'deleteJson' => $this->deleteJson($uri),
                default => $this->postJson($uri, $payload),
            };

            $this->assertSame(401, $response->getStatusCode(), $uri);
        }
    }

    public function test_an_application_that_never_existed_is_a_plain_404(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);

        $response = $this->getJson('/api/v1/applications/999999/documents');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('SQL', $response->getContent());
        $this->assertStringNotContainsString('Eloquent', $response->getContent());
    }

    public function test_the_vault_document_stays_behind_its_own_endpoint(): void
    {
        $user = $this->customer('Sample Customer');
        $this->as($user);
        $application = $this->application();
        $document = $this->upload();

        $this->postJson("/api/v1/applications/{$application}/documents", [
            'requirement_code' => 'income',
            'document_id' => $document,
        ])->assertOk();

        $body = $this->getJson("/api/v1/applications/{$application}/documents")
            ->getContent();

        // The checklist names the document and gives its id; reading it is
        // still FynnVault's own authenticated route and nothing else.
        $this->assertStringContainsString('"id":"'.$document.'"', $body);

        foreach ([
            'download_url', 'url', 'storage', 'signed', '/documents/'.$document.'/download',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }

        // And another customer still cannot fetch it.
        $intruder = $this->customer('Intruder Person');
        $this->as($intruder);
        $this->get("/api/v1/documents/{$document}/download")->assertNotFound();
    }
}
