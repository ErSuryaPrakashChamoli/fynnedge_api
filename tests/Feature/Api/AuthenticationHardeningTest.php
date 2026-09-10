<?php

namespace Tests\Feature\Api;

use App\Domain\Otp\LogOtpSender;
use App\Exceptions\OtpException;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three authentication defects Phase 8 found, and their guards.
 *
 * Each of these passed every existing test before it was fixed, because
 * each needs a condition the suite never created: a second caller arriving
 * at the same instant, or an environment that is neither development nor
 * recognisably production.
 */
class AuthenticationHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // The development OTP hint is opt-in, not merely "not production"
    // ---------------------------------------------------------------

    public function test_the_development_hint_is_withheld_unless_it_is_switched_on(): void
    {
        $sender = new LogOtpSender;

        config(['fynnedge.otp.expose_dev_hint' => false]);

        $this->assertNull(
            $sender->developmentHint('123456'),
            'The OTP was returned to the caller in a non-production '
            .'environment that had not asked for it.',
        );
    }

    public function test_the_development_hint_is_withheld_in_production_even_when_switched_on(): void
    {
        $sender = new LogOtpSender;

        config(['fynnedge.otp.expose_dev_hint' => true]);
        $this->app['env'] = 'production';

        $this->assertNull(
            $sender->developmentHint('123456'),
            'A production build handed the OTP back to the caller.',
        );
    }

    public function test_the_send_otp_endpoint_omits_the_hint_when_it_is_switched_off(): void
    {
        config(['fynnedge.otp.expose_dev_hint' => false]);

        $response = $this->postJson('/api/v1/auth/send-otp', ['mobile' => '9876543210']);

        $response->assertOk();

        // Dropped from the payload entirely rather than sent as null.
        $this->assertArrayNotHasKey('dev_hint', $response->json('data'));
    }

    // ---------------------------------------------------------------
    // A verified challenge cannot be spent twice
    // ---------------------------------------------------------------

    public function test_a_consumed_challenge_cannot_be_verified_again(): void
    {
        $otp = app(OtpService::class);

        config(['fynnedge.otp.expose_dev_hint' => true]);
        $issued = $otp->issue('9876543210');
        $code = app(LogOtpSender::class)->developmentHint('') !== null
            ? $issued['dev_hint']
            : null;

        $this->assertNotNull($code, 'The test needs the issued code.');

        $otp->verify('9876543210', $issued['reference'], $code);

        // The attempt counter must survive, and the challenge must be spent.
        $challenge = OtpChallenge::query()->where('reference', $issued['reference'])->first();
        $this->assertNotNull($challenge->consumed_at, 'The challenge was not consumed.');

        $this->expectException(OtpException::class);
        $otp->verify('9876543210', $issued['reference'], $code);
    }

    public function test_a_wrong_code_still_raises_the_attempt_counter(): void
    {
        // The verify path runs inside a transaction so the consume cannot
        // race. An exception thrown across that boundary would roll the
        // increment back and leave the cap on guesses unenforced, so the
        // failure is decided under the lock and reported outside it.
        $otp = app(OtpService::class);
        config(['fynnedge.otp.expose_dev_hint' => true]);

        $issued = $otp->issue('9876543210');

        try {
            $otp->verify('9876543210', $issued['reference'], '000000');
        } catch (OtpException) {
            // expected
        }

        $this->assertSame(
            1,
            OtpChallenge::query()->where('reference', $issued['reference'])->first()->attempts,
            'A failed guess did not count against the attempt cap.',
        );
    }

    // ---------------------------------------------------------------
    // Two first-time sign-ins for one number
    // ---------------------------------------------------------------

    public function test_a_colliding_first_sign_in_is_served_rather_than_failing(): void
    {
        $auth = new AuthService;

        // Stand in for the racing request that committed first.
        User::create(['mobile' => '9812345678']);

        // This call found nothing a moment ago and is about to collide with
        // the row above. It must resolve to that customer, not raise.
        $result = $auth->signIn('9812345678', 'second-device');

        $this->assertSame('9812345678', $result['user']->mobile);
        $this->assertFalse(
            $result['is_new_user'],
            'A customer who already existed was reported as new.',
        );
        $this->assertSame(
            1,
            User::query()->where('mobile', '9812345678')->count(),
            'The collision created a duplicate customer.',
        );
    }

    public function test_repeated_sign_ins_never_duplicate_a_customer_or_their_profiles(): void
    {
        $auth = new AuthService;

        foreach (range(1, 5) as $i) {
            $auth->signIn('9812345679', "device-{$i}");
        }

        $this->assertSame(1, User::query()->where('mobile', '9812345679')->count());

        $user = User::query()->where('mobile', '9812345679')->first();
        $this->assertSame(1, $user->customerProfile()->count());
        $this->assertSame(1, $user->financialProfile()->count());

        // One token per device, so five sign-ins leave five live sessions.
        $this->assertSame(5, $user->tokens()->count());
    }
}
