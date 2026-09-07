<?php

namespace Tests\Feature\Lender;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\LenderApplicationMapper;
use App\Domain\Lender\LenderApplicationPayload;
use App\Domain\Lender\PayloadNotPermitted;
use App\Domain\Lender\SubmissionFieldMap;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A mapper cannot send what SubmissionFieldMap refuses.
 *
 * SubmissionFieldMap lists twelve fields FynnEdge sends, each with a
 * purpose, and thirteen it refuses, each with a reason. That list was
 * checked only against the fixture mapper's own output — which proved the
 * fixture mapper behaves, and nothing about the next one. The mapper is the
 * component most likely to be rewritten against a real lender's
 * documentation, and the one whose mistakes leave the building.
 *
 * NO LIVE LENDER SUBMISSION PROVIDER IS CONNECTED. These tests exist so
 * that stays true safely rather than by luck.
 */
class OutboundPayloadBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fynnedge.lender.driver' => 'fixture']);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update([
            'full_name' => 'Sample Customer',
            'city' => 'Pune',
            'age' => 34,
        ]);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::LenderDataSharing,
            'application_review',
        );

        return $user;
    }

    private function application(): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    /** A mapper written against a provider's field list, not this project's. */
    private function generousMapper(array $extra): void
    {
        $this->app->bind(LenderApplicationMapper::class, fn () => new class($extra) implements LenderApplicationMapper
        {
            public function __construct(private array $extra) {}

            public function map(LoanApplication $application): LenderApplicationPayload
            {
                return LenderApplicationPayload::of(
                    fynnEdgeReference: $application->reference,
                    providerProductCode: 'PROVIDER-CODE',
                    fields: [
                        'fynnedge_reference' => $application->reference,
                        'product_code' => 'PROVIDER-CODE',
                        'amount' => 1000000,
                        ...$this->extra,
                    ],
                );
            }
        });
    }

    // --- The boundary object -----------------------------------------------

    /** @return iterable<string, array{string, mixed}> */
    public static function refusedFields(): iterable
    {
        yield 'a PAN' => ['document_number', 'AAAAA0000A'];
        yield 'a credit score' => ['credit_score', 742];
        yield 'a FynnScore' => ['fynn_score', 68];
        yield 'a scan extraction' => ['scan_extraction', ['net_pay' => 120000]];
        yield 'a KYC result' => ['kyc_result', 'verified'];
        yield 'documents' => ['documents', ['identity.pdf']];
        yield 'savings' => ['savings', 600000];
        yield 'goals' => ['goals', ['a house']];
        yield 'AI conversations' => ['ai_conversations', ['hello']];
        yield 'internal ids' => ['internal_ids', [1, 2, 3]];
    }

    #[DataProvider('refusedFields')]
    public function test_a_refused_field_cannot_be_put_in_a_payload(string $field, mixed $value): void
    {
        $this->expectException(PayloadNotPermitted::class);

        LenderApplicationPayload::of('FE-1', 'CODE', [
            'fynnedge_reference' => 'FE-1',
            $field => $value,
        ]);
    }

    public function test_a_field_nobody_listed_at_all_is_refused(): void
    {
        // Refused by default. A field with no written purpose is a field
        // nobody decided to send.
        $this->expectException(PayloadNotPermitted::class);

        LenderApplicationPayload::of('FE-1', 'CODE', [
            'applicant_pan_status' => 'active',
        ]);
    }

    public function test_the_permitted_fields_are_accepted(): void
    {
        // A boundary that refuses everything is not a boundary.
        $payload = LenderApplicationPayload::of('FE-1', 'CODE', array_fill_keys(
            SubmissionFieldMap::sentKeys(),
            'value',
        ));

        $this->assertSame(SubmissionFieldMap::sentKeys(), $payload->fieldNames());
    }

    public function test_a_partial_payload_is_allowed(): void
    {
        // Omitting a field is a mapper's business; adding one is not.
        $payload = LenderApplicationPayload::of('FE-1', 'CODE', [
            'fynnedge_reference' => 'FE-1',
            'product_code' => 'CODE',
        ]);

        $this->assertSame(['fynnedge_reference', 'product_code'], $payload->fieldNames());
    }

    // --- The live path -----------------------------------------------------

    public function test_a_mapper_that_adds_a_pan_sends_nothing_and_records_nothing(): void
    {
        $this->customer();
        $id = $this->application();

        $this->generousMapper(['document_number' => 'AAAAA0000A']);

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'payload_not_permitted');

        // The payload is built before the lock and before any attempt row,
        // so a mapper bug leaves the application exactly as it was.
        $this->assertSame(0, ApplicationSubmission::count());

        $application = LoanApplication::findOrFail($id);
        $this->assertSame('not_attempted', $application->latestSubmission()?->outcome ?? 'not_attempted');
    }

    public function test_the_customer_is_not_told_which_field_a_mapper_got_wrong(): void
    {
        $this->customer();
        $id = $this->application();

        $this->generousMapper(['credit_score' => 742]);

        $body = $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(409)
            ->getContent();

        // Which field a mapper got wrong is FynnEdge's problem, and the
        // value itself must not travel back either.
        $this->assertStringNotContainsString('credit_score', $body);
        $this->assertStringNotContainsString('742', $body);
        $this->assertStringContainsString('safe in FynnEdge', $body);
    }

    public function test_a_conforming_mapper_still_submits(): void
    {
        $this->customer();
        $id = $this->application();

        $this->generousMapper(['monthly_income' => 150000]);

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $this->assertSame(1, ApplicationSubmission::count());
    }
}
