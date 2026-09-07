<?php

namespace Tests\Feature\Lender;

use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Catalog\ProviderType;
use App\Domain\Consent\ConsentType;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a submission must not change.
 *
 * A catalogue repricing must not reprice an application, and sending an
 * application must not alter it, the customer's score, or which products
 * match them.
 */
class SubmissionIntegrityTest extends TestCase
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
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune', 'age' => 34]);
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

    private function application(string $productId = 'prod_bl_b'): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => $productId,
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    // --- A repricing is not a repricing of history --------------------------

    public function test_a_catalogue_repricing_never_reprices_a_submitted_application(): void
    {
        config(['fynnedge.catalogue.driver' => 'database']);
        $catalogue = app(CatalogueService::class);

        $provider = $catalogue->createProvider(
            code: 'sample_bank',
            name: 'Sample Bank',
            type: ProviderType::Bank,
            isSample: true,
        );

        $product = $catalogue->createProduct(
            provider: $provider,
            productCode: 'term_loan',
            displayName: 'Term Loan',
            category: 'personal',
        );

        $attributes = [
            'effective_from' => '2020-01-01',
            'effective_until' => null,
            'interest_rate' => 11.5,
            'processing_fee_percent' => 1.0,
            'processing_fee_cap' => '15000.00',
            'prepayment_charge' => 'Nil after 12 EMIs',
            'min_amount' => '100000.00',
            'max_amount' => '2000000.00',
            'min_tenure_months' => 12,
            'max_tenure_months' => 60,
            'min_monthly_income' => '30000.00',
            'min_age' => 23,
            'max_age_at_maturity' => 58,
            'fynn_trust' => 80,
            'trust_breakdown' => [],
            'lender_tag' => '',
            'suited_reasons' => ['Published rate holds for the full term'],
            'considerations' => ['Sample product'],
            'conditions' => [],
            'source_type' => ProductSourceType::Sample->value,
            'source_reference' => null,
            'source_retrieved_at' => null,
        ];

        $v1 = $catalogue->createDraftVersion($product, $attributes);
        $catalogue->publishVersion($v1);
        $catalogue->setProductStatus($product, ProductStatus::Active);

        $this->customer();
        $id = $this->application($product->public_id);

        $created = LoanApplication::find($id);
        $this->assertSame('11.500', $created->interest_rate);

        // The version identity is recorded alongside the snapshot, so the
        // application can be traced to the version that offered 11.5%.
        $this->assertSame($v1->fresh()->getKey(), $created->product_version_id);
        $this->assertSame($product->public_id, $created->catalogue_product_code);

        $originalEmi = $created->emi;

        // The provider reprices. A new version replaces the old.
        $draft = $catalogue->cloneToDraft($v1->fresh(), [
            'interest_rate' => 12.25,
            'effective_from' => now()->addDay()->toDateString(),
        ]);
        $catalogue->closeVersion($v1->fresh(), now()->toDateString());
        $catalogue->publishVersion($draft);

        $this->travelTo(now()->addDays(2));

        // Submitted after the change.
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $after = LoanApplication::find($id);

        // The application is exactly what the customer applied under.
        $this->assertSame('11.500', $after->interest_rate);
        $this->assertSame($originalEmi, $after->emi);
        $this->assertSame($v1->fresh()->getKey(), $after->product_version_id);

        // And what left carried the snapshot, not today's price.
        $this->assertSame(
            $created->product_id,
            ApplicationSubmission::first()->product_id,
        );
    }

    public function test_submitting_mutates_nothing_about_the_application(): void
    {
        $this->customer();
        $id = $this->application();

        $before = $this->getJson("/api/v1/applications/{$id}")->json('data');

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $after = $this->getJson("/api/v1/applications/{$id}")->json('data');

        // Sending an application is not editing one. Every pricing figure,
        // the match category and the reference are untouched.
        foreach ([
            'reference', 'product_id', 'amount', 'tenure_months',
            'interest_rate', 'emi', 'total_interest', 'total_payable',
            'processing_fee', 'match_category', 'status',
        ] as $field) {
            $this->assertSame(
                $before[$field] ?? null,
                $after[$field] ?? null,
                "submission changed {$field}",
            );
        }
    }

    // --- Nothing downstream moves -------------------------------------------

    public function test_submitting_does_not_change_the_fynn_score(): void
    {
        $this->customer();
        $id = $this->application();

        $before = $this->getJson('/api/v1/fynn-score')->json('data');

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        // A submission says nothing about financial health.
        $this->assertSame($before, $this->getJson('/api/v1/fynn-score')->json('data'));
        $this->assertCount(3, $before['dimensions']);
    }

    public function test_submitting_does_not_change_fynn_match(): void
    {
        $this->customer();
        $id = $this->application();

        $uri = '/api/v1/fynn-match?amount=500000&tenure_months=48&purpose=personal';
        $before = $this->getJson($uri)->json('data');

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        // No recommendation is recalculated, and no category quietly moves.
        $this->assertSame($before, $this->getJson($uri)->json('data'));
    }

    public function test_the_submission_payload_reaches_fynn_ai_in_no_form(): void
    {
        $user = $this->customer();
        app(ConsentService::class)->grant($user, ConsentType::AiMemory, 'privacy_screen');

        $id = $this->application();
        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();

        $context = $this->getJson('/api/v1/ai/context')->assertOk()->getContent();

        foreach ([
            'BL-002', 'provider_product_code', 'fields_sent', 'idempotency',
            'submission', 'fixture', 'applicant_mobile', $user->mobile,
        ] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $context, $leak);
        }
    }

    public function test_no_vault_document_or_storage_detail_is_involved(): void
    {
        $this->customer();
        $id = $this->application();

        $body = $this->postJson("/api/v1/applications/{$id}/submission")
            ->getContent();

        // Module 27 will handle documents. Nothing about them is sent
        // because a document happens to exist.
        foreach ([
            'documents', 'storage_key', 'storage_disk', '/storage/', '.pdf',
            'document_id',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, $leak);
        }

        $this->assertNotContains(
            'documents',
            ApplicationSubmission::first()->fields_sent,
        );
    }

    // --- Cancellation semantics ---------------------------------------------

    public function test_a_local_cancel_never_claims_a_provider_withdrawal(): void
    {
        $this->customer();
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/submission")->assertOk();
        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();

        $body = $this->getJson("/api/v1/applications/{$id}")->getContent();

        // Module 9's cancel is FynnEdge's own. It must never read as a
        // lender application being withdrawn.
        foreach ([
            'lender application cancelled', 'withdrawn from the lender',
            'provider cancelled', 'cancelled with the provider',
        ] as $claim) {
            $this->assertStringNotContainsStringIgnoringCase($claim, $body, $claim);
        }

        // The submission record survives, because the attempt happened.
        $this->assertSame(1, ApplicationSubmission::count());
    }

    public function test_a_cancelled_application_cannot_be_submitted(): void
    {
        $this->customer();
        $id = $this->application();

        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();

        $this->postJson("/api/v1/applications/{$id}/submission")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'application_not_submittable');

        $this->assertDatabaseCount('application_submissions', 0);
    }
}
