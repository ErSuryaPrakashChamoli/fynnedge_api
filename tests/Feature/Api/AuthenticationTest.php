<?php

namespace Tests\Feature\Api;

use App\Models\OtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const MOBILE = '9876543210';

    /** @return array{reference: string, code: string} */
    private function requestOtp(string $mobile = self::MOBILE): array
    {
        $response = $this->postJson('/api/v1/auth/send-otp', ['mobile' => $mobile])
            ->assertOk();

        return [
            'reference' => $response->json('data.reference'),
            'code' => $response->json('data.dev_hint'),
        ];
    }

    public function test_it_issues_a_challenge(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile' => self::MOBILE])
            ->assertOk()
            ->assertJsonStructure(['data' => ['reference', 'expires_in', 'dev_hint']]);

        $this->assertDatabaseCount('otp_challenges', 1);
    }

    public function test_the_code_is_never_stored_in_plain_text(): void
    {
        $issued = $this->requestOtp();
        $challenge = OtpChallenge::first();

        $this->assertNotSame($issued['code'], $challenge->code_hash);
        $this->assertTrue(Hash::check($issued['code'], $challenge->code_hash));
    }

    public function test_it_rejects_a_malformed_mobile_number(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile' => '12345'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile');

        // Indian mobiles start 6-9.
        $this->postJson('/api/v1/auth/send-otp', ['mobile' => '1234567890'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile');
    }

    public function test_verifying_a_correct_code_returns_a_token_and_profile(): void
    {
        $issued = $this->requestOtp();

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonStructure([
                'data' => ['token', 'is_new_user', 'expires_at', 'profile'],
            ]);

        $this->assertDatabaseHas('users', ['mobile' => self::MOBILE]);
    }

    public function test_a_new_customer_gets_both_profiles_on_first_sign_in(): void
    {
        $issued = $this->requestOtp();

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ])->assertOk();

        $user = User::where('mobile', self::MOBILE)->firstOrFail();
        $this->assertNotNull($user->customerProfile);
        $this->assertNotNull($user->financialProfile);
    }

    public function test_a_returning_customer_is_not_flagged_as_new(): void
    {
        User::factory()->create(['mobile' => self::MOBILE]);
        $issued = $this->requestOtp();

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false);
    }

    public function test_a_wrong_code_is_rejected_and_counted(): void
    {
        $issued = $this->requestOtp();

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => '000000',
        ])->assertStatus(422);

        $this->assertSame(1, OtpChallenge::first()->attempts);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $issued = $this->requestOtp();
        $payload = [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ];

        $this->postJson('/api/v1/auth/verify-otp', $payload)->assertOk();

        // The same code a second time must not produce another session.
        $this->postJson('/api/v1/auth/verify-otp', $payload)->assertStatus(422);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $issued = $this->requestOtp();
        OtpChallenge::first()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ])->assertStatus(422);
    }

    public function test_attempts_are_capped(): void
    {
        $issued = $this->requestOtp();
        OtpChallenge::first()->update([
            'attempts' => config('fynnedge.otp.max_attempts'),
        ]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ])->assertStatus(429);
    }

    public function test_a_resend_retires_the_previous_challenge(): void
    {
        $first = $this->requestOtp();
        $second = $this->requestOtp();

        // The old code must stop working the moment a new one is sent.
        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $first['reference'],
            'code' => $first['code'],
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => self::MOBILE,
            'reference' => $second['reference'],
            'code' => $second['code'],
        ])->assertOk();
    }

    public function test_a_challenge_cannot_be_used_for_a_different_number(): void
    {
        $issued = $this->requestOtp('9876543210');

        $this->postJson('/api/v1/auth/verify-otp', [
            'mobile' => '9123456789',
            'reference' => $issued['reference'],
            'code' => $issued['code'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['mobile' => '9123456789']);
    }
}
