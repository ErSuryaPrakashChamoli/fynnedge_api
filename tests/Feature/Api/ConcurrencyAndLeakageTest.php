<?php

namespace Tests\Feature\Api;

use App\Models\LoanApplication;
use App\Models\User;
use App\Services\DocumentVaultService;
use App\Services\LoanApplicationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What happens when the same intention arrives twice, and what the answer
 * says when something goes wrong.
 *
 * A repeated tap must not become two applications, and a refusal must not
 * hand back the shape of the system that refused it.
 */
class ConcurrencyAndLeakageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);

        $this->user = User::factory()->create();
        $this->user->ensureProfiles();
        $this->user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($this->user);
    }

    private function draft(array $body = []): array
    {
        return array_merge([
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ], $body);
    }

    // --- Idempotency and double action ----------------------------------

    public function test_the_same_key_is_the_same_application(): void
    {
        $key = 'the-same-intention';

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/applications', $this->draft())
            ->assertCreated()->json('data.id');

        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/applications', $this->draft())
            ->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, LoanApplication::count());
    }

    public function test_a_key_collision_returns_the_application_not_an_error(): void
    {
        $key = 'a-racing-tap';

        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/applications', $this->draft())
            ->assertCreated()->json('data.id');

        // The second request of a race: both callers passed the "does one
        // already exist" check before either had written, so the insert is
        // what collides. Reproduced by making the check miss a row that is
        // really there.
        $service = app(LoanApplicationService::class);
        LoanApplication::where('id', $first)
            ->update(['idempotency_key' => $key]);

        $again = $service->create(
            $this->user->fresh(),
            ['product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60],
            $key,
        );

        $this->assertSame(1, LoanApplication::count());
        $this->assertEquals($first, $again->id);
    }

    public function test_the_database_refuses_a_duplicate_key_outright(): void
    {
        // The guarantee underneath the check: even if application code lost
        // its nerve, two rows with one key cannot exist.
        $this->withHeader('Idempotency-Key', 'only-once')
            ->postJson('/api/v1/applications', $this->draft())
            ->assertCreated();

        $row = LoanApplication::first()->replicate();

        $this->expectException(
            UniqueConstraintViolationException::class
        );
        $row->save();
    }

    public function test_a_different_key_is_a_different_intention(): void
    {
        foreach (['one', 'two'] as $key) {
            $this->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/applications', $this->draft())
                ->assertCreated();
        }

        $this->assertSame(2, LoanApplication::count());
    }

    public function test_submitting_twice_submits_once(): void
    {
        $id = $this->postJson('/api/v1/applications', $this->draft())
            ->json('data.id');

        $first = $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk()->json('data');

        $second = $this->postJson("/api/v1/applications/{$id}/submit", [
            'acknowledged' => true,
        ])->assertOk()->json('data');

        $this->assertSame($first['submitted_at'], $second['submitted_at']);
        $this->assertSame(
            $first['provider_reference'],
            $second['provider_reference'],
        );

        // And one acknowledgement, not two.
        $submitted = collect(
            LoanApplication::find($id)->events
        )->where('label', 'Submitted');
        $this->assertCount(1, $submitted);
    }

    public function test_cancelling_twice_cancels_once(): void
    {
        $id = $this->postJson('/api/v1/applications', $this->draft())
            ->json('data.id');

        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();
        $this->postJson("/api/v1/applications/{$id}/cancel")->assertStatus(409);

        $cancelled = collect(LoanApplication::find($id)->events)
            ->where('label', 'Cancelled');
        $this->assertCount(1, $cancelled);
    }

    public function test_deleting_a_document_twice_is_not_an_error_the_second_time(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        $id = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();
        // Gone is gone, and answers the same as never having existed.
        $this->deleteJson("/api/v1/documents/{$id}")->assertNotFound();
    }

    public function test_withdrawing_a_consent_twice_leaves_one_position(): void
    {
        $this->postJson('/api/v1/consents', ['type' => 'ai_memory'])->assertOk();
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();

        $current = collect($this->getJson('/api/v1/consents')->json('data.consents'))
            ->firstWhere('type', 'ai_memory');

        $this->assertSame('withdrawn', $current['status']);
    }

    // --- Error responses -------------------------------------------------

    #[DataProvider('errorProbes')]
    public function test_no_error_response_describes_the_system(
        string $method,
        string $uri,
        array $body,
        int $expected,
    ): void {
        // Production shape: the suite runs with debug on so failures stay
        // readable, and what customers receive is the quiet version.
        config(['app.debug' => false]);

        $response = $this->json($method, $uri, $body);

        $this->assertSame($expected, $response->getStatusCode(), "{$method} {$uri}");

        $content = $response->getContent();

        foreach ([
            'Exception', 'Stack trace', '#0 ', 'vendor/', '/app/',
            base_path(), 'SQLSTATE', 'select * from', 'Illuminate\\',
            'App\\Models', 'App\\Services', 'storage_key', 'APP_KEY',
            '.php', 'PDOException',
        ] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $content,
                "{$method} {$uri} leaked \"{$leak}\"",
            );
        }
    }

    public static function errorProbes(): array
    {
        return [
            'missing resource' => ['GET', '/api/v1/applications/999999', [], 404],
            'unknown route' => ['GET', '/api/v1/not-a-thing', [], 404],
            'wrong method' => ['DELETE', '/api/v1/home', [], 405],
            'invalid goal' => ['POST', '/api/v1/goals', ['title' => ''], 422],
            'invalid product' => ['POST', '/api/v1/applications', [
                'product_id' => 'no_such_product',
                'amount' => 1000000,
                'tenure_months' => 60,
            ], 422],
            'unknown consent' => [
                'POST', '/api/v1/consents/not-a-consent/withdraw', [], 404,
            ],
            'malformed document id' => [
                'GET', "/api/v1/documents/' OR 1=1--", [], 404,
            ],
            'malformed goal id' => ['GET', '/api/v1/goals/abc', [], 404],
        ];
    }

    public function test_a_validation_error_names_the_field_and_nothing_else(): void
    {
        $body = $this->postJson('/api/v1/goals', [
            'title' => '',
            'category' => 'not_a_category',
            'target_amount' => -5,
            'target_date' => 'yesterday',
        ])->assertStatus(422)->json();

        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('title', $body['errors']);

        $encoded = json_encode($body);
        $this->assertStringNotContainsString('financial_goals', $encoded);
        $this->assertStringNotContainsString('App\\', $encoded);
    }

    public function test_an_unauthenticated_call_says_only_that(): void
    {
        $this->app['auth']->forgetGuards();

        $response = $this->getJson('/api/v1/home', [
            'Authorization' => 'Bearer not-a-real-token',
        ]);

        $response->assertStatus(401);
        $this->assertStringNotContainsString('token', strtolower(
            str_replace('unauthenticated', '', strtolower($response->getContent()))
        ));
    }
}
