<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The OTP endpoints are the only unauthenticated write surface in the API.
 * Without a limiter they are an SMS-cost attack and a brute-force target.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('otp-send:mobile:9876543210');
        RateLimiter::clear('otp-verify:mobile:9876543210');
    }

    public function test_otp_sends_are_capped_per_mobile_number(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/send-otp', ['mobile' => '9876543210'])
                ->assertOk();
        }

        $this->postJson('/api/v1/auth/send-otp', ['mobile' => '9876543210'])
            ->assertStatus(429);
    }

    public function test_the_cap_is_per_number_not_global(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/send-otp', ['mobile' => '9876543210'])
                ->assertOk();
        }

        // A different customer must not be locked out by someone else's spam.
        $this->postJson('/api/v1/auth/send-otp', ['mobile' => '9123456780'])
            ->assertOk();
    }

    public function test_verification_attempts_are_capped(): void
    {
        $issued = $this->postJson('/api/v1/auth/send-otp', [
            'mobile' => '9876543210',
        ])->json('data');

        // Six wrong guesses are allowed through the throttle; the seventh is
        // refused before it ever reaches the OTP service.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/verify-otp', [
                'mobile' => '9876543210',
                'reference' => $issued['reference'],
                'code' => '000000',
            ]);
        }

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => '9876543210',
            'reference' => $issued['reference'],
            'code' => '000000',
        ])->assertStatus(429);
    }

    public function test_vault_writes_carry_their_own_limit(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);
        Storage::fake(DocumentVaultService::DISK);

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        // Every upload is up to 10 MB of stored bytes. Without a limit of
        // its own, the generous authenticated allowance would let one
        // account write gigabytes a minute.
        $statuses = [];
        for ($i = 0; $i < 22; $i++) {
            $statuses[] = $this->postJson('/api/v1/documents', [
                'file' => new UploadedFile(
                    $path, "payslip-{$i}.pdf", 'application/pdf', null, true
                ),
                'type' => 'income',
            ])->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Vault writes are unbounded');
        $this->assertSame(
            201,
            $statuses[0],
            'The limit is tighter than ordinary use',
        );
    }

    public function test_reading_the_vault_is_not_rate_limited_by_writes(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);
        Storage::fake(DocumentVaultService::DISK);

        // Looking at your own documents costs nothing and must stay free.
        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/v1/documents')->assertOk();
        }
    }
}
