<?php

namespace Tests\Feature\ApplicationDocuments;

use App\Domain\Lender\LenderSubmissionProvider;
use App\Domain\Lender\ProviderSubmissionResult;
use App\Models\ApplicationDocument;
use App\Models\Document;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Preparing an application: which documents it asks for, which of the
 * customer's own fill them, and whether it is ready.
 *
 * Nothing in this module sends anything anywhere. LIVE LENDER DOCUMENT
 * TRANSMISSION: NO.
 */
class DocumentChecklistTest extends TestCase
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
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /** A business loan: identity, income, bank required; existing loan optional. */
    private function application(string $productId = 'prod_bl_b'): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => $productId,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    private function upload(string $type): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n{$type}\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, "{$type}.pdf", 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    private function checklist(string $applicationId): array
    {
        return $this->getJson("/api/v1/applications/{$applicationId}/documents")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed>|null */
    private function item(array $checklist, string $code): ?array
    {
        foreach ($checklist['items'] as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }

        return null;
    }

    private function attach(string $applicationId, string $code, string $documentId)
    {
        return $this->postJson("/api/v1/applications/{$applicationId}/documents", [
            'requirement_code' => $code,
            'document_id' => $documentId,
        ]);
    }

    private function review(string $applicationId, string $code, string $decision)
    {
        return $this->postJson(
            "/api/v1/applications/{$applicationId}/documents/{$code}/review",
            ['decision' => $decision],
        );
    }

    /** Fills every required slot and confirms it. */
    private function makeReady(string $applicationId): void
    {
        foreach (['identity', 'income', 'bank'] as $code) {
            $this->attach($applicationId, $code, $this->upload($code))->assertOk();
            $this->review($applicationId, $code, 'accepted')->assertOk();
        }
    }

    // --- The checklist ------------------------------------------------------

    public function test_an_application_states_what_it_asks_for(): void
    {
        $this->customer();
        $checklist = $this->checklist($this->application());

        $this->assertSame(
            ['identity', 'income', 'bank', 'existing_loan'],
            array_column($checklist['items'], 'code'),
        );

        // Every slot starts empty, and says what to do about it.
        foreach ($checklist['items'] as $item) {
            $this->assertSame('missing', $item['status']);
            $this->assertSame('Add from FynnVault', $item['action']);
            $this->assertNull($item['document']);
        }

        $this->assertFalse($checklist['ready']);
        $this->assertSame(3, $checklist['required_count']);
        $this->assertSame(0, $checklist['ready_count']);
    }

    public function test_requirements_follow_the_kind_of_loan(): void
    {
        $this->customer();

        // A home loan involves the property; a business loan does not.
        $home = $this->checklist($this->application('prod_hl_a'));
        $this->assertContains('property', array_column($home['items'], 'code'));

        $business = $this->checklist($this->application('prod_bl_b'));
        $this->assertNotContains('property', array_column($business['items'], 'code'));
    }

    public function test_the_optional_document_never_blocks(): void
    {
        $this->customer();
        $id = $this->application();

        $optional = $this->item($this->checklist($id), 'existing_loan');
        $this->assertFalse($optional['required']);
        $this->assertFalse($optional['blocks_readiness']);

        $this->makeReady($id);

        // Every required slot confirmed, the optional one untouched, and
        // the application is ready.
        $checklist = $this->checklist($id);
        $this->assertTrue($checklist['ready']);
        $this->assertSame('missing', $this->item($checklist, 'existing_loan')['status']);
    }

    public function test_readiness_needs_every_required_document(): void
    {
        $this->customer();
        $id = $this->application();

        $this->attach($id, 'identity', $this->upload('identity'))->assertOk();
        $this->review($id, 'identity', 'accepted')->assertOk();
        $this->assertFalse($this->checklist($id)['ready']);

        $this->attach($id, 'income', $this->upload('income'))->assertOk();
        $this->review($id, 'income', 'accepted')->assertOk();
        $this->assertFalse($this->checklist($id)['ready']);

        $this->attach($id, 'bank', $this->upload('bank'))->assertOk();

        // Attached but not confirmed: still not ready.
        $checklist = $this->checklist($id);
        $this->assertSame('available', $this->item($checklist, 'bank')['status']);
        $this->assertFalse($checklist['ready']);

        $this->review($id, 'bank', 'accepted')->assertOk();
        $this->assertTrue($this->checklist($id)['ready']);
    }

    // --- Choosing is not confirming -----------------------------------------

    public function test_attaching_a_document_does_not_confirm_it(): void
    {
        $this->customer();
        $id = $this->application();
        $documentId = $this->upload('income');

        $this->attach($id, 'income', $documentId)->assertOk();

        $item = $this->item($this->checklist($id), 'income');

        $this->assertSame('available', $item['status']);
        $this->assertSame('Confirm this document', $item['action']);
        $this->assertNull($item['confirmed_at']);
        $this->assertTrue($item['blocks_readiness']);

        // The document is identified by what a customer recognises.
        $this->assertSame($documentId, $item['document']['id']);
        $this->assertSame('income', $item['document']['type']);
    }

    public function test_rejecting_records_the_customers_judgement(): void
    {
        $this->customer();
        $id = $this->application();

        $this->attach($id, 'income', $this->upload('income'))->assertOk();
        $this->review($id, 'income', 'rejected')->assertOk();

        $item = $this->item($this->checklist($id), 'income');

        $this->assertSame('not_suitable', $item['status']);
        $this->assertSame('Choose another document', $item['action']);
        $this->assertTrue($item['blocks_readiness']);
    }

    public function test_choosing_again_replaces_rather_than_accumulates(): void
    {
        $this->customer();
        $id = $this->application();

        $first = $this->upload('income');
        $second = $this->upload('income');

        $this->attach($id, 'income', $first)->assertOk();
        $this->review($id, 'income', 'accepted')->assertOk();

        $this->attach($id, 'income', $second)->assertOk();

        // One current choice per slot, never an ambiguous pair — and the
        // new one is unconfirmed, because they have not looked at it yet.
        $this->assertSame(1, ApplicationDocument::where('requirement_code', 'income')->count());

        $item = $this->item($this->checklist($id), 'income');
        $this->assertSame($second, $item['document']['id']);
        $this->assertSame('available', $item['status']);
    }

    public function test_removing_a_choice_leaves_the_vault_document_alone(): void
    {
        $this->customer();
        $id = $this->application();
        $documentId = $this->upload('income');

        $this->attach($id, 'income', $documentId)->assertOk();
        $this->deleteJson("/api/v1/applications/{$id}/documents/income")->assertOk();

        $this->assertSame('missing', $this->item($this->checklist($id), 'income')['status']);

        // The document itself is untouched and still downloadable.
        $this->getJson("/api/v1/documents/{$documentId}")->assertOk();
        $this->get("/api/v1/documents/{$documentId}/download")->assertOk();
    }

    // --- Wrong documents ----------------------------------------------------

    public function test_a_document_of_the_wrong_kind_is_refused(): void
    {
        $this->customer();
        $id = $this->application();

        $this->attach($id, 'identity', $this->upload('bank'))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'wrong_document_type');

        $this->assertSame('missing', $this->item($this->checklist($id), 'identity')['status']);
    }

    public function test_a_requirement_this_application_does_not_have_is_refused(): void
    {
        $this->customer();
        $id = $this->application('prod_bl_b');

        // A business loan asks for no property document.
        $this->attach($id, 'property', $this->upload('property'))
            ->assertStatus(404)
            ->assertJsonPath('reason', 'document_requirement_missing');

        // Nor does an invented code create a slot.
        $this->attach($id, 'anything_i_like', $this->upload('income'))
            ->assertStatus(404);

        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_a_nonexistent_document_is_refused(): void
    {
        $this->customer();
        $id = $this->application();

        $this->attach($id, 'income', '999999')
            ->assertStatus(404)
            ->assertJsonPath('reason', 'document_not_found');
    }

    public function test_confirming_nothing_is_refused(): void
    {
        $this->customer();
        $id = $this->application();

        $this->review($id, 'income', 'accepted')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'no_document_selected');
    }

    // --- Documents that go away ---------------------------------------------

    public function test_a_deleted_document_can_never_stay_ready(): void
    {
        $this->customer();
        $id = $this->application();
        $documentId = $this->upload('income');

        $this->attach($id, 'income', $documentId)->assertOk();
        $this->review($id, 'income', 'accepted')->assertOk();
        $this->assertSame('ready', $this->item($this->checklist($id), 'income')['status']);

        $this->deleteJson("/api/v1/documents/{$documentId}")->assertNoContent();

        $item = $this->item($this->checklist($id), 'income');

        // Missing, with the reason and the action — never left as ready
        // pointing at a document that is gone.
        $this->assertSame('missing', $item['status']);
        $this->assertNull($item['document']);
        $this->assertStringContainsString(
            'no longer in FynnVault',
            $item['notice'],
        );
        $this->assertFalse($this->checklist($id)['ready']);
    }

    public function test_a_deleted_document_cannot_be_confirmed(): void
    {
        $this->customer();
        $id = $this->application();
        $documentId = $this->upload('income');

        $this->attach($id, 'income', $documentId)->assertOk();
        $this->deleteJson("/api/v1/documents/{$documentId}")->assertNoContent();

        $this->review($id, 'income', 'accepted')
            ->assertStatus(409)
            ->assertJsonPath('reason', 'document_not_usable');
    }

    public function test_a_changed_file_invalidates_the_confirmation(): void
    {
        $this->customer();
        $id = $this->application();
        $documentId = $this->upload('income');

        $this->attach($id, 'income', $documentId)->assertOk();
        $this->review($id, 'income', 'accepted')->assertOk();
        $this->assertTrue($this->checklist($id)['ready'] === false);

        // The bytes behind the document change.
        $document = Document::find($documentId);
        $document->disk()->put($document->storage_key, '%PDF-1.4 different');

        $item = $this->item($this->checklist($id), 'income');

        // The confirmation described a different file, so it does not
        // carry over.
        $this->assertSame('needs_review', $item['status']);
        $this->assertStringContainsString('has changed since you confirmed', $item['notice']);
        $this->assertTrue($item['blocks_readiness']);
    }

    // --- Application state --------------------------------------------------

    public function test_a_cancelled_application_accepts_no_document_changes(): void
    {
        $this->customer();
        $id = $this->application();
        $documentId = $this->upload('income');

        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();

        $this->attach($id, 'income', $documentId)
            ->assertStatus(409)
            ->assertJsonPath('reason', 'application_not_editable');

        $this->review($id, 'income', 'accepted')->assertStatus(409);
        $this->deleteJson("/api/v1/applications/{$id}/documents/income")
            ->assertStatus(409);

        // But the checklist is still readable, so the application stays
        // understandable after it is closed.
        $this->getJson("/api/v1/applications/{$id}/documents")->assertOk();
    }

    public function test_a_submitted_application_still_supports_preparation(): void
    {
        $this->customer();
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk();

        // Module 9's submit means the customer finished it in FynnEdge.
        // Nothing has gone anywhere, so documents can still be prepared.
        $this->attach($id, 'income', $this->upload('income'))->assertOk();
    }

    // --- The boundary this module must not cross ----------------------------

    public function test_document_readiness_never_transmits_anything(): void
    {
        config(['fynnedge.lender.driver' => 'fixture']);

        $this->customer();
        $id = $this->application();

        $spy = new class implements LenderSubmissionProvider
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

            public function displayName(): ?string
            {
                return 'Spy';
            }

            public function role(): string
            {
                return 'simulated';
            }

            public function isSimulated(): bool
            {
                return true;
            }

            public function submit($payload, string $idempotencyKey): ProviderSubmissionResult
            {
                $this->calls++;

                return ProviderSubmissionResult::simulated(
                    'spy',
                    now(),
                    '',
                );
            }
        };

        $this->app->instance(LenderSubmissionProvider::class, $spy);

        // The whole document journey, to fully ready.
        $this->makeReady($id);
        $this->assertTrue($this->checklist($id)['ready']);

        // And no provider was called at any point.
        $this->assertSame(
            0,
            $spy->calls,
            'preparing documents called a lender submission provider',
        );

        // The application has not been sent, and says so.
        $this->getJson("/api/v1/applications/{$id}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'not_attempted')
            ->assertJsonPath('data.submission.provider_received', false);

        $this->assertDatabaseCount('application_submissions', 0);
    }

    public function test_readiness_never_reads_as_a_lender_having_anything(): void
    {
        $this->customer();
        $id = $this->application();
        $this->makeReady($id);

        $body = $this->getJson("/api/v1/applications/{$id}/documents")->getContent();

        foreach ([
            'verified', 'approved', 'accepted by', 'lender', 'guaranteed',
            'kyc complete', 'sent to', 'received by', 'transmitted',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase(
                $claim,
                str_replace(
                    [
                        'It does not send them to any lender or provider',
                        'Nothing has been sent anywhere yet',
                    ],
                    '',
                    $body,
                ),
                $claim,
            );
        }

        // What it does say.
        $this->assertStringContainsString('Nothing has been sent anywhere yet', $body);
        $this->assertStringContainsString('no one outside FynnEdge has seen', $body);
    }

    // --- Nothing about the document is copied -------------------------------

    public function test_no_storage_detail_or_content_reaches_the_response(): void
    {
        $user = $this->customer();
        $id = $this->application();
        $this->makeReady($id);

        $body = $this->getJson("/api/v1/applications/{$id}/documents")->getContent();

        foreach ([
            'storage_key', 'storage_disk', 'file_digest', '/storage/',
            'private', 'u'.$user->id.'/', '%PDF',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }

        // And nothing of the file is in the database either.
        $stored = json_encode(ApplicationDocument::all()->toArray());
        foreach (['%PDF', 'storage_key', 'file_digest'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored, $leak);
        }
    }

    public function test_nothing_else_about_the_customer_changes(): void
    {
        $user = $this->customer();
        $id = $this->application();

        $before = [
            'score' => $this->getJson('/api/v1/fynn-score')->json('data'),
            'profile' => $this->getJson('/api/v1/financial-profile')->json('data'),
            'application' => $this->getJson("/api/v1/applications/{$id}")->json('data.emi'),
        ];

        $this->makeReady($id);

        // No KYC, no OCR, no score change, no repricing.
        $this->assertSame($before['score'], $this->getJson('/api/v1/fynn-score')->json('data'));
        $this->assertSame(
            $before['profile'],
            $this->getJson('/api/v1/financial-profile')->json('data'),
        );
        $this->assertSame(
            $before['application'],
            $this->getJson("/api/v1/applications/{$id}")->json('data.emi'),
        );

        $this->assertDatabaseCount('document_scans', 0);
        $this->assertDatabaseCount('kyc_verifications', 0);
        $this->assertDatabaseCount('credit_reports', 0);
    }

    public function test_the_customers_own_timeline_records_what_they_did(): void
    {
        $this->customer();
        $id = $this->application();

        $this->attach($id, 'income', $this->upload('income'))->assertOk();
        $this->review($id, 'income', 'accepted')->assertOk();

        $events = collect(
            $this->getJson("/api/v1/applications/{$id}")->json('data.events')
        );

        $confirmed = $events->firstWhere('label', 'Document confirmed');

        $this->assertNotNull($confirmed);
        $this->assertSame('fynnedge', $confirmed['source']);
        $this->assertStringContainsString(
            'Nothing has been sent anywhere',
            $confirmed['detail'],
        );
    }

    // --- Historical integrity ------------------------------------------------

    public function test_requirements_follow_the_application_not_the_catalogue(): void
    {
        $this->customer();
        $id = $this->application('prod_bl_b');

        $before = array_column($this->checklist($id)['items'], 'code');

        // The application's own snapshotted category decides what it asks
        // for, so nothing about the catalogue can rewrite an existing
        // application's requirements.
        LoanApplication::find($id)->forceFill([
            'product_id' => 'some_other_product',
        ])->save();

        $this->assertSame($before, array_column($this->checklist($id)['items'], 'code'));
    }
}
