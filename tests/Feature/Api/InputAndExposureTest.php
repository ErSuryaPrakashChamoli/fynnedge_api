<?php

namespace Tests\Feature\Api;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the server accepts, and what it hands back.
 *
 * The app validates as a courtesy to the person typing. The server validates
 * because it is the only thing that has to be right — so the awkward inputs
 * are sent straight at it here, past any screen.
 */
class InputAndExposureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);

        $this->user = User::factory()->create();
        $this->user->ensureProfiles();
        $this->user->customerProfile->update(['full_name' => 'Real Customer']);
        $this->user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($this->user);
    }

    // --- Input ------------------------------------------------------------

    #[DataProvider('hostileFinancialInput')]
    public function test_the_server_refuses_impossible_figures(array $body): void
    {
        $before = $this->user->financialProfile->monthly_income;

        $this->putJson('/api/v1/financial-profile', $body)
            ->assertStatus(422);

        $this->assertEquals(
            $before,
            $this->user->financialProfile->fresh()->monthly_income,
            'A rejected save changed the stored profile',
        );
    }

    public static function hostileFinancialInput(): array
    {
        $valid = [
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 0,
            'savings' => 600000,
            'employment_type' => 'salaried',
        ];

        return [
            'negative income' => [['monthly_income' => -1] + $valid],
            'negative expenses' => [['monthly_expenses' => -50000] + $valid],
            'negative savings' => [['savings' => -1] + $valid],
            'income as text' => [['monthly_income' => 'a lot'] + $valid],
            'income as array' => [['monthly_income' => [1, 2]] + $valid],
            'income as null' => [['monthly_income' => null] + $valid],
            'absurd income' => [['monthly_income' => 1e18] + $valid],
            'infinite income' => [['monthly_income' => '1e400'] + $valid],
            'unknown employment' => [['employment_type' => 'astronaut'] + $valid],
            'missing income' => [array_diff_key($valid, ['monthly_income' => null])],
        ];
    }

    #[DataProvider('hostileGoalInput')]
    public function test_the_server_refuses_impossible_goals(array $body): void
    {
        $this->postJson('/api/v1/goals', $body)->assertStatus(422);
        $this->assertSame(0, $this->user->goals()->count());
    }

    public static function hostileGoalInput(): array
    {
        $valid = [
            'title' => 'A goal',
            'category' => 'business',
            'target_amount' => 500000,
            'target_date' => '2030-01-01',
        ];

        return [
            'empty title' => [['title' => ''] + $valid],
            'whitespace title' => [['title' => '   '] + $valid],
            'one character' => [['title' => 'a'] + $valid],
            'enormous title' => [['title' => str_repeat('a', 5000)] + $valid],
            'unknown category' => [['category' => 'yacht'] + $valid],
            'zero target' => [['target_amount' => 0] + $valid],
            'negative target' => [['target_amount' => -100] + $valid],
            'absurd target' => [['target_amount' => 1e15] + $valid],
            'date in the past' => [['target_date' => '2020-01-01'] + $valid],
            'today' => [['target_date' => 'today'] + $valid],
            'not a date' => [['target_date' => 'someday'] + $valid],
            'saved beyond target' => [
                ['saved_amount' => 999999] + $valid,
            ],
        ];
    }

    public function test_a_unicode_name_is_kept_exactly(): void
    {
        $this->putJson('/api/v1/profile', [
            'full_name' => 'वेंकटेश अय्यर',
            'city' => 'कोच्चि',
        ])->assertOk();

        $this->assertSame(
            'वेंकटेश अय्यर',
            $this->user->customerProfile->fresh()->full_name,
        );
        $this->assertSame('वेंकटेश', $this->getJson('/api/v1/home')
            ->json('data.customer.first_name'));
    }

    public function test_markup_in_a_name_is_refused_rather_than_stored(): void
    {
        // Nothing in FynnEdge renders a name as HTML today. A stored script
        // tag is a payload waiting for the first thing that does — an email,
        // an export, an operator's screen — so it does not get stored.
        foreach ([
            '<script>alert(1)</script>',
            'Rahul <img src=x onerror=alert(1)>',
            "Rahul\u{202E}drowssap",
        ] as $payload) {
            $this->putJson('/api/v1/profile', [
                'full_name' => $payload,
                'city' => 'Pune',
            ]);
        }

        $stored = $this->user->customerProfile->fresh()->full_name;
        $this->assertStringNotContainsString('<', $stored);
        $this->assertStringNotContainsString('>', $stored);

        $body = $this->getJson('/api/v1/profile')->getContent();
        $this->assertStringNotContainsString('<script', $body);
    }

    #[DataProvider('malformedIdentifiers')]
    public function test_a_malformed_identifier_is_just_not_found(
        string $id,
    ): void {
        foreach ([
            "/api/v1/goals/{$id}",
            "/api/v1/applications/{$id}",
            "/api/v1/documents/{$id}",
            "/api/v1/ai/conversations/{$id}",
        ] as $uri) {
            $status = $this->getJson($uri)->getStatusCode();
            $this->assertContains(
                $status,
                [404, 405],
                "{$uri} answered {$status}",
            );
        }
    }

    public static function malformedIdentifiers(): array
    {
        return [
            'letters' => ['abc'],
            'negative' => ['-1'],
            'huge' => ['99999999999999999999'],
            'sql' => ['1 OR 1=1'],
            'union' => ['1 UNION SELECT * FROM users'],
            'decimal' => ['1.5'],
            'zero' => ['0'],
        ];
    }

    // --- What comes back --------------------------------------------------

    public function test_no_response_carries_a_field_the_screen_cannot_use(): void
    {
        $this->postJson('/api/v1/goals', [
            'title' => 'A goal',
            'category' => 'business',
            'target_amount' => 500000,
            'target_date' => '2030-01-01',
        ])->assertCreated();

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'payslip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated();

        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated();

        foreach ([
            '/api/v1/home',
            '/api/v1/profile',
            '/api/v1/financial-profile',
            '/api/v1/goals',
            '/api/v1/applications',
            '/api/v1/documents',
            '/api/v1/consents',
            '/api/v1/ai/context',
            '/api/v1/ai/memory',
            '/api/v1/fynn-score',
        ] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();

            foreach ([
                // Ownership is settled by the token; repeating it in every
                // payload only teaches a client to send it back.
                '"user_id"', '"customer_id"',
                // Where a file lives, and on what.
                'storage_key', 'storage_disk',
                // Credentials and internals of any kind.
                'password', 'remember_token', 'api_key', 'secret',
                'personal_access_token', 'code_hash',
                // Framework and filesystem shape.
                base_path(), 'Illuminate\\', 'App\\Models',
            ] as $exposure) {
                $this->assertStringNotContainsString(
                    $exposure,
                    $body,
                    "{$uri} exposes {$exposure}",
                );
            }
        }
    }

    public function test_the_ai_context_carries_only_what_it_is_allowed(): void
    {
        $context = $this->getJson('/api/v1/ai/context')->assertOk()->json('data');

        // Module 13's boundary, checked as a shape rather than by reading.
        $this->assertArrayNotHasKey('token', $context);
        $this->assertArrayNotHasKey('password', $context);
        $this->assertArrayNotHasKey('documents_content', $context);

        $encoded = json_encode($context);
        foreach (['%PDF', 'storage_key', 'Bearer ', 'code_hash'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }

    public function test_the_otp_challenge_never_returns_the_stored_hash(): void
    {
        $challenge = $this->postJson('/api/v1/auth/send-otp', [
            'mobile' => '9000009999',
        ])->assertOk()->json('data');

        $this->assertArrayNotHasKey('code_hash', $challenge);
        $this->assertArrayHasKey('reference', $challenge);

        // The development hint exists outside production and is the code
        // itself; it must never be the stored hash.
        $stored = OtpChallenge::latest('id')->first();
        $this->assertNotSame($stored->code_hash, $challenge['dev_hint'] ?? null);
        $this->assertNotSame(
            $stored->code_hash,
            json_encode($challenge),
        );
    }
}
