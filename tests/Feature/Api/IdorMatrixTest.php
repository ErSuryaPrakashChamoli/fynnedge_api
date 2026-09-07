<?php

namespace Tests\Feature\Api;

use App\Domain\Consent\ConsentType;
use App\Models\FinancialGoal;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One customer, every resource, every verb, someone else's token.
 *
 * The ownership pattern across the API is always the same — resolve through
 * the authenticated user's own relation, and answer identically whether the
 * row belongs to someone else or does not exist. This walks that pattern
 * rather than trusting it, because the cost of one controller forgetting is
 * another customer's finances.
 */
class IdorMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);

        $this->owner = $this->customer('Owner Person', 250000);
        $this->intruder = $this->customer('Intruder Person', 90000);
    }

    private function customer(string $name, int $income): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => $name]);
        $user->financialProfile->update([
            'monthly_income' => $income,
            'monthly_expenses' => 30000,
            'existing_emi' => 10000,
            'savings' => 500000,
        ]);

        return $user;
    }

    /** Runs a closure as one customer and restores nobody afterwards. */
    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function pdf(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true);
    }

    /** Everything the owner has, created as the owner. */
    private function ownedResources(): array
    {
        $this->as($this->owner);

        $goal = $this->postJson('/api/v1/goals', [
            'title' => 'Owner goal',
            'category' => 'business',
            'target_amount' => 500000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $document = $this->postJson('/api/v1/documents', [
            'file' => $this->pdf(),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $conversation = $this->postJson('/api/v1/ai/conversations', [
            'title' => 'Owner conversation',
        ])->assertCreated()->json('data.id');

        return compact('goal', 'application', 'document', 'conversation');
    }

    public function test_no_verb_on_any_resource_answers_to_the_wrong_customer(): void
    {
        $owned = $this->ownedResources();
        $this->as($this->intruder);

        // Method, path, and the status the established policy requires. A
        // resource that is not yours is indistinguishable from one that does
        // not exist, so the answer is 404 rather than 403 — a 403 would
        // confirm the row is real.
        $probes = [
            ['GET', "/api/v1/goals/{$owned['goal']}", 404],
            ['PUT', "/api/v1/goals/{$owned['goal']}", 404],
            ['DELETE', "/api/v1/goals/{$owned['goal']}", 404],

            ['GET', "/api/v1/applications/{$owned['application']}", 404],
            ['PATCH', "/api/v1/applications/{$owned['application']}", 404],
            ['PUT', "/api/v1/applications/{$owned['application']}", 404],
            ['POST', "/api/v1/applications/{$owned['application']}/submit", 404],
            ['POST', "/api/v1/applications/{$owned['application']}/cancel", 404],

            ['GET', "/api/v1/documents/{$owned['document']}", 404],
            ['GET', "/api/v1/documents/{$owned['document']}/download", 404],
            ['DELETE', "/api/v1/documents/{$owned['document']}", 404],
            ['GET', "/api/v1/documents/{$owned['document']}/scan", 404],
            ['POST', "/api/v1/documents/{$owned['document']}/scan", 404],

            ['GET', "/api/v1/ai/conversations/{$owned['conversation']}", 404],
            ['DELETE', "/api/v1/ai/conversations/{$owned['conversation']}", 404],
            ['POST', "/api/v1/ai/conversations/{$owned['conversation']}/messages", 404],
        ];

        foreach ($probes as [$method, $uri, $expected]) {
            $response = $this->json($method, $uri, $this->bodyFor($uri));

            $this->assertSame(
                $expected,
                $response->getStatusCode(),
                "{$method} {$uri} answered {$response->getStatusCode()} to a "
                .'customer who does not own it',
            );

            // And says nothing about what it refused.
            $this->assertStringNotContainsString(
                'Owner Person',
                $response->getContent(),
            );
            $this->assertStringNotContainsString(
                'Owner goal',
                $response->getContent(),
            );
        }
    }

    /** Bodies that would otherwise fail validation before ownership. */
    private function bodyFor(string $uri): array
    {
        if (str_contains($uri, '/goals/')) {
            return [
                'title' => 'Intruder rename',
                'category' => 'business',
                'target_amount' => 100000,
                'target_date' => now()->addYear()->toDateString(),
            ];
        }

        if (str_ends_with($uri, '/submit')) {
            return ['acknowledged' => true];
        }

        if (str_ends_with($uri, '/messages')) {
            return ['text' => 'Tell me about this customer'];
        }

        if (str_contains($uri, '/applications/')) {
            return ['amount' => 900000, 'tenure_months' => 48];
        }

        return [];
    }

    public function test_a_second_customers_list_endpoints_show_only_their_own(): void
    {
        $this->ownedResources();
        $this->as($this->intruder);

        foreach ([
            '/api/v1/goals',
            '/api/v1/applications',
            '/api/v1/documents',
            '/api/v1/ai/conversations',
            '/api/v1/ai/memory',
            '/api/v1/consents',
            '/api/v1/home',
            '/api/v1/profile',
            '/api/v1/financial-profile',
            '/api/v1/fynn-score',
            '/api/v1/financial-summary',
        ] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();

            $this->assertStringNotContainsString('Owner Person', $body, $uri);
            $this->assertStringNotContainsString('Owner goal', $body, $uri);
            $this->assertStringNotContainsString('payslip.pdf', $body, $uri);
            // The owner's income, which the intruder does not share.
            $this->assertStringNotContainsString('250000', $body, $uri);
        }
    }

    public function test_an_identity_in_the_payload_never_wins_over_the_token(): void
    {
        $this->ownedResources();
        $ownerId = $this->owner->id;
        $this->as($this->intruder);

        // Every shape a client might try to steer identity with.
        $injections = [
            'user_id' => $ownerId,
            'customer_id' => $ownerId,
            'id' => $ownerId,
            'userId' => $ownerId,
            'owner_id' => $ownerId,
        ];

        $home = $this->getJson('/api/v1/home?'.http_build_query($injections))
            ->assertOk()->json('data');
        $this->assertSame(90000, $home['financial']['monthly_income']);

        $goals = $this->getJson('/api/v1/goals?'.http_build_query($injections))
            ->assertOk()->json('data');
        $this->assertSame([], $goals);

        // And on a write: the goal is created for the caller, not the id.
        $created = $this->postJson('/api/v1/goals', array_merge($injections, [
            'title' => 'Whose goal is this',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ]))->assertCreated()->json('data.id');

        $this->assertSame(
            $this->intruder->id,
            FinancialGoal::find($created)->user_id,
            'A user_id in the payload decided who owns the new goal',
        );
    }

    public function test_a_customer_cannot_reach_another_customers_document_bytes(): void
    {
        $owned = $this->ownedResources();
        $this->as($this->intruder);

        $response = $this->get("/api/v1/documents/{$owned['document']}/download");

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF', $response->getContent());
    }

    /**
     * FynnScan reads a document, so the ordinary IDOR shape applies: an id
     * belonging to somebody else must be indistinguishable from one that
     * does not exist, on every verb.
     */
    public function test_no_scan_verb_answers_to_the_wrong_customer(): void
    {
        config(['fynnedge.scan.driver' => 'fixture']);

        $this->as($this->owner);
        app(ConsentService::class)->grant(
            $this->owner,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        $document = $this->postJson('/api/v1/documents', [
            'file' => $this->pdf(),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$document}/scan")->assertOk();

        $this->as($this->intruder);
        app(ConsentService::class)->grant(
            $this->intruder,
            ConsentType::DocumentProcessing,
            'privacy_screen',
        );

        foreach ([
            ['get', "/api/v1/documents/{$document}/scan"],
            ['post', "/api/v1/documents/{$document}/scan"],
            ['post', "/api/v1/documents/{$document}/scan/review"],
        ] as [$verb, $uri]) {
            $response = $verb === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri, [
                    'field' => 'net_income',
                    'decision' => 'confirmed',
                    'apply_to_profile' => true,
                ]);

            $this->assertSame(404, $response->getStatusCode(), "{$verb} {$uri}");

            // And nothing about what the owner's document said comes back.
            $this->assertStringNotContainsString('75000', $response->getContent());
            $this->assertStringNotContainsString('Northwind', $response->getContent());
        }

        // The intruder's own profile is untouched by the attempt.
        $this->assertSame(
            '90000.00',
            (string) $this->intruder->financialProfile->fresh()->monthly_income,
        );
    }

    /**
     * External submission may create an application at a third party, so
     * another customer's id must reach nothing and cause no provider call.
     */
    public function test_no_submission_verb_answers_to_the_wrong_customer(): void
    {
        config(['fynnedge.lender.driver' => 'fixture']);

        $this->as($this->owner);
        app(ConsentService::class)->grant(
            $this->owner,
            ConsentType::LenderDataSharing,
            'application_review',
        );

        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $this->as($this->intruder);
        app(ConsentService::class)->grant(
            $this->intruder,
            ConsentType::LenderDataSharing,
            'application_review',
        );

        foreach ([
            ['get', "/api/v1/applications/{$application}/submission"],
            ['post', "/api/v1/applications/{$application}/submission"],
        ] as [$verb, $uri]) {
            $response = $verb === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri);

            $this->assertSame(404, $response->getStatusCode(), "{$verb} {$uri}");
            $this->assertStringNotContainsString('FE-2026', $response->getContent());
        }

        // And nothing was sent on anyone's behalf.
        $this->assertDatabaseCount('application_submissions', 0);
    }

    /**
     * Identity verification, like FynnScan, hangs off a document, so the
     * ordinary IDOR shape applies on every verb — and a 404 must not even
     * reveal that a verification record exists.
     */
    public function test_no_kyc_verb_answers_to_the_wrong_customer(): void
    {
        config(['fynnedge.kyc.driver' => 'fixture']);

        $this->as($this->owner);
        $this->owner->customerProfile->update(['full_name' => 'Sample Customer']);

        app(ConsentService::class)->grant(
            $this->owner,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );

        $document = $this->postJson('/api/v1/documents', [
            'file' => $this->pdf(),
            'type' => 'identity',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$document}/kyc")->assertOk();

        $this->as($this->intruder);
        app(ConsentService::class)->grant(
            $this->intruder,
            ConsentType::IdentityVerification,
            'privacy_screen',
        );

        foreach ([
            ['get', "/api/v1/documents/{$document}/kyc"],
            ['post', "/api/v1/documents/{$document}/kyc"],
            ['post', "/api/v1/documents/{$document}/kyc/resolve"],
        ] as [$verb, $uri]) {
            $response = $verb === 'get'
                ? $this->getJson($uri)
                : $this->postJson($uri, [
                    'field' => 'name',
                    'decision' => 'update_profile',
                ]);

            $this->assertSame(404, $response->getStatusCode(), "{$verb} {$uri}");

            // Nothing about the owner's identity comes back.
            foreach (['Sample Customer', 'XXXXXX000A', 'verified'] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $response->getContent(),
                    $leak,
                );
            }
        }

        // The intruder's own profile is untouched by the attempt.
        $this->assertSame(
            'Intruder Person',
            $this->intruder->customerProfile->fresh()->full_name,
        );
    }

    /**
     * Credit information has no id in its URL, so the usual IDOR shape does
     * not apply. The equivalent question is whether the session alone
     * decides whose credit is read, and whether one customer's report can
     * surface in another's response.
     */
    public function test_credit_information_follows_the_session_and_nothing_else(): void
    {
        config(['fynnedge.credit.driver' => 'fixture']);

        $this->as($this->owner);
        app(ConsentService::class)->grant(
            $this->owner,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );
        $this->postJson('/api/v1/credit/check')->assertOk();

        $this->assertSame(1, $this->owner->creditReports()->count());

        // The intruder has granted nothing and has no report. Their own
        // status must say so, whatever they put in the request.
        $this->as($this->intruder);

        foreach ([
            ['user_id' => $this->owner->id],
            ['customer_id' => $this->owner->id],
            ['id' => $this->owner->id],
        ] as $payload) {
            $this->postJson('/api/v1/credit/check', $payload)
                ->assertForbidden()
                ->assertJsonPath('reason', 'consent_required');
        }

        $status = $this->getJson('/api/v1/credit/status')->assertOk();
        // Their own state, not the owner's: they granted nothing, so they
        // are asked for consent rather than shown a report.
        $status->assertJsonPath('data.status', 'consent_required')
            ->assertJsonPath('data.profile', null);

        $this->assertSame(0, $this->intruder->creditReports()->count());
        $this->assertStringNotContainsString(
            'SAMPLE-SCORE-V1',
            $status->getContent(),
        );
    }
}
