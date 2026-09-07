<?php

namespace Tests\Feature\Scan;

use App\Domain\Consent\ConsentType;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureDocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureScenario;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * FynnScan: what a document appears to contain, and what that is not.
 *
 * NO LIVE DOCUMENT PROVIDER IS CONNECTED. Everything here runs against a
 * fictional one. No real document, real person, real PAN, real Aadhaar and
 * no real bank account is involved anywhere in this suite.
 */
class FynnScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.scan.driver' => 'fixture']);
    }

    private function scenario(FixtureScenario $scenario): void
    {
        config(['fynnedge.scan.fixture_scenario' => $scenario->value]);
        $this->app->forgetInstance(DocumentUnderstandingProvider::class);
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

        return $user;
    }

    private function consent(User $user): void
    {
        app(ConsentService::class)->grant(
            $user,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );
    }

    private function payslip(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true);
    }

    private function stored(string $type = 'income'): string
    {
        return $this->postJson('/api/v1/documents', [
            'file' => $this->payslip(),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function field(array $data, string $key): ?array
    {
        foreach ($data['extraction'] ?? [] as $field) {
            if ($field['key'] === $key) {
                return $field;
            }
        }

        return null;
    }

    // --- Consent is the gate ------------------------------------------------

    public function test_a_stored_document_is_not_permission_to_send_it(): void
    {
        $this->customer();
        $id = $this->stored();

        $this->getJson("/api/v1/documents/{$id}/scan")
            ->assertOk()
            ->assertJsonPath('data.status', 'consent_required')
            ->assertJsonPath('data.extraction', null);

        $this->postJson("/api/v1/documents/{$id}/scan")
            ->assertForbidden()
            ->assertJsonPath('reason', 'consent_required');

        // Nothing was sent, so nothing was recorded.
        $this->assertDatabaseCount('document_scans', 0);
    }

    public function test_withdrawing_consent_removes_every_reading(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $id = $this->stored();

        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();
        $this->assertSame(1, $user->documentScans()->count());

        $this->postJson('/api/v1/consents/document_processing/withdraw')
            ->assertSuccessful();

        $this->assertSame(0, $user->documentScans()->count());
        $this->assertDatabaseCount('document_scan_fields', 0);

        // The ledger keeps both decisions. That a customer granted and then
        // withdrew is a record that has to survive.
        $history = $this->getJson('/api/v1/consents/history')->json('data');
        $entries = array_values(array_filter(
            $history,
            fn ($r) => $r['type'] === 'document_processing',
        ));
        $this->assertCount(2, $entries);
    }

    public function test_the_consent_is_not_offered_without_a_provider(): void
    {
        config(['fynnedge.scan.driver' => 'none']);
        $this->customer();

        $consents = collect($this->getJson('/api/v1/consents')->json('data.consents'));
        $processing = $consents->firstWhere('type', 'document_processing');

        $this->assertFalse($processing['is_available']);
        $this->assertNull($processing['current_version']);
        $this->assertNotNull($processing['unavailable_reason']);

        // And it cannot be recorded.
        $this->postJson('/api/v1/consents', [
            'type' => 'document_processing',
        ])->assertStatus(422);
    }

    // --- The seven ways a provider behaves ----------------------------------

    #[DataProvider('scenarios')]
    public function test_every_provider_behaviour_produces_an_honest_state(
        FixtureScenario $scenario,
        string $expectedStatus,
        ?string $expectedReason,
    ): void {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario($scenario);

        $id = $this->stored();
        $response = $this->postJson("/api/v1/documents/{$id}/scan");
        $data = $response->json('data');

        $this->assertSame($expectedStatus, $data['status'], $scenario->value);

        if ($expectedReason !== null) {
            $this->assertSame($expectedReason, $data['reason']);
        }

        // Whatever happened, nothing was written to the customer's profile.
        $this->assertSame(
            '60000.00',
            (string) $user->financialProfile->fresh()->monthly_income,
            'a scan changed a financial profile',
        );
    }

    public static function scenarios(): array
    {
        return [
            'everything read cleanly' => [
                FixtureScenario::Good, 'completed', null,
            ],
            'one field the provider was unsure about' => [
                FixtureScenario::LowConfidence, 'needs_review', null,
            ],
            'a field the document does not carry' => [
                FixtureScenario::MissingField, 'needs_review', null,
            ],
            'two candidates and nothing to choose between them' => [
                FixtureScenario::ConflictingField, 'needs_review', null,
            ],
            'an amount in a notation no contract defines' => [
                FixtureScenario::AmbiguousAmount, 'needs_review', null,
            ],
            'a date that could be two months' => [
                FixtureScenario::AmbiguousDate, 'needs_review', null,
            ],
            'the provider says it is a different document' => [
                FixtureScenario::Misclassified, 'needs_review', null,
            ],
            'a response FynnEdge cannot read' => [
                FixtureScenario::Unreadable, 'failed', 'unreadable_document',
            ],
            'the provider could not read the file' => [
                FixtureScenario::UnreadableDocument, 'failed', 'unreadable_document',
            ],
            'the provider is down' => [
                FixtureScenario::ProviderUnavailable, 'failed', 'provider_error',
            ],
            'the provider did not answer in time' => [
                FixtureScenario::ProviderTimeout, 'failed', 'provider_timeout',
            ],
        ];
    }

    public function test_a_failed_scan_carries_no_fields_at_all(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::ProviderUnavailable);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/scan")->json('data');

        $this->assertSame('failed', $data['status']);

        // Null, not an empty list. An empty extraction reads downstream as a
        // reading that ran and found nothing.
        $this->assertNull($data['extraction']);
        $this->assertDatabaseCount('document_scan_fields', 0);

        // And the customer is told what happened, not a provider's code.
        $this->assertStringNotContainsString('fixture', (string) $data['message']);
        $this->assertStringNotContainsString('500', (string) $data['message']);
    }

    public function test_an_unsupported_document_family_is_never_half_read(): void
    {
        $user = $this->customer();
        $this->consent($user);

        foreach (['identity', 'bank', 'loan', 'property', 'other'] as $type) {
            $id = $this->stored($type);

            $this->getJson("/api/v1/documents/{$id}/scan")
                ->assertOk()
                ->assertJsonPath('data.is_supported', false)
                ->assertJsonPath('data.extraction', null);

            $this->postJson("/api/v1/documents/{$id}/scan")
                ->assertStatus(501)
                ->assertJsonPath('reason', 'document_family_not_supported');
        }

        $this->assertDatabaseCount('document_scans', 0);
    }

    // --- The reading itself -------------------------------------------------

    public function test_a_clean_reading_carries_value_confidence_and_evidence(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/scan")->assertOk()->json('data');

        $this->assertSame('completed', $data['status']);
        $this->assertSame('income_v1', $data['schema_version']);
        $this->assertTrue($data['is_sample']);

        $net = $this->field($data, 'net_income');
        $this->assertSame('75000.00', $net['value']);
        $this->assertSame('money', $net['value_type']);
        $this->assertSame(FixtureDocumentUnderstandingProvider::NET, $net['amount']);
        $this->assertSame('high', $net['confidence']);
        $this->assertFalse($net['needs_review']);

        // Where it came from, so the customer can check it against their own
        // copy of the document.
        $this->assertSame(1, $net['evidence']['page']);
        $this->assertSame('Net Pay', $net['evidence']['section']);
        $this->assertNotEmpty($net['evidence']['snippet']);

        // Gross and net stay two separate fields. Which one a customer's
        // "monthly income" means is a business rule, and there isn't one.
        $gross = $this->field($data, 'gross_income');
        $this->assertSame(FixtureDocumentUnderstandingProvider::GROSS, $gross['amount']);
        $this->assertNotSame($gross['value'], $net['value']);
    }

    public function test_confidence_is_never_a_percentage(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/scan")->getContent();

        // The provider's own numbers stay behind the normalizer. 0.97 means
        // whatever that provider defines it to mean, and "97%" would invent
        // both a precision and an interpretation nobody measured.
        foreach (['0.97', '0.98', '0.96', '97%', '98%', 'extractionScore'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }

        $data = json_decode($body, true)['data'];
        foreach ($data['extraction'] as $field) {
            $this->assertContains(
                $field['confidence'],
                ['high', 'medium', 'low', 'unavailable'],
            );
        }
    }

    public function test_an_ambiguous_amount_is_never_guessed_into_a_number(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::AmbiguousAmount);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/scan")->json('data');

        $net = $this->field($data, 'net_income');

        // "75K" is only an amount if a contract says it is, and none does.
        $this->assertNull($net['amount']);
        $this->assertSame('unavailable', $net['confidence']);
        $this->assertTrue($net['needs_review']);
        $this->assertNotNull($net['review_reason']);
    }

    public function test_an_ambiguous_date_is_never_guessed_into_a_month(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::AmbiguousDate);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/scan")->json('data');

        $period = $this->field($data, 'pay_period');

        // 01/02/26 is either 1 February or 2 January depending on a
        // convention the document does not state.
        $this->assertTrue($period['needs_review']);
        $this->assertNull($data['document_period']);
    }

    public function test_two_candidate_values_become_a_question_not_a_choice(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::ConflictingField);

        $id = $this->stored();
        $data = $this->postJson("/api/v1/documents/{$id}/scan")->json('data');

        $net = $this->field($data, 'net_income');
        $this->assertTrue($net['needs_review']);
        $this->assertStringContainsString('more than one', $net['review_reason']);
        $this->assertStringContainsString('72,400', $net['review_reason']);
    }

    public function test_a_disagreement_about_the_document_type_never_refiles_it(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->scenario(FixtureScenario::Misclassified);

        $id = $this->stored('income');
        $data = $this->postJson("/api/v1/documents/{$id}/scan")->json('data');

        $this->assertTrue($data['classification_mismatch']);
        $this->assertSame('needs_review', $data['status']);

        // The vault still says what the customer said. FynnEdge reports the
        // disagreement; it does not re-file a customer's document for them.
        $this->assertSame(
            'income',
            $this->getJson("/api/v1/documents/{$id}")->json('data.type'),
        );
    }

    // --- Not one byte more than the feature needs ---------------------------

    public function test_the_providers_surplus_pii_is_discarded(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/scan")->getContent();

        // The fixture deliberately hands back a payslip's whole contents.
        // None of it is needed to answer whether a stated income matches a
        // document, so none of it is kept, logged or returned.
        foreach ([
            'AAAAA0000A', 'aadhaarNumber', '0000 0000 0000', 'panNumber',
            '1990-01-01', 'Invented Street', '00000000000', 'SMPL0000000',
            'Provident Fund', 'Professional Tax', 'rawText', 'consumer',
            'deductions', 'bankAccountNumber', 'dateOfBirth',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "returned: {$leak}");
        }

        // Nor is any of it in the database.
        $stored = json_encode([
            DocumentScan::with('fields')->get()->toArray(),
        ]);

        foreach (['AAAAA0000A', '0000 0000 0000', 'Invented Street', 'rawText'] as $leak) {
            $this->assertStringNotContainsString($leak, $stored, "persisted: {$leak}");
        }
    }

    public function test_provider_vocabulary_never_reaches_the_customer(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        // Every surface, not only the scan response.
        foreach ([
            "/api/v1/documents/{$id}/scan",
            "/api/v1/documents/{$id}",
            '/api/v1/documents',
            '/api/v1/financial-profile',
            '/api/v1/fynn-score',
            '/api/v1/home',
        ] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();

            foreach ([
                'netPay', 'grossPay', 'payCycle', 'employeeFullName',
                'employerName', 'extractionScore', 'documentClass',
                'pageNumber', 'modelVersion', 'fixture-payslip',
            ] as $field) {
                $this->assertStringNotContainsString(
                    $field,
                    $body,
                    "{$uri} leaked the provider field: {$field}",
                );
            }
        }
    }

    public function test_nothing_about_storage_or_the_provider_internals_leaks(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $id = $this->stored();
        $body = $this->postJson("/api/v1/documents/{$id}/scan")->getContent();

        foreach ([
            'storage_key', 'storage_disk', 'private', '.pdf', 'u'.$user->id.'/',
            'provider_reference', 'file_digest', 'model_version',
            'api_key', 'secret', 'endpoint', 'Stack trace',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $body, $internal);
        }
    }
}
