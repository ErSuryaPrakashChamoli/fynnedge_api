<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

/**
 * Who is allowed to tell FynnEdge who the customer is.
 *
 * Every HTTPS deployment puts something in front of PHP, and until that
 * something is trusted Laravel reads the proxy's address as the client's.
 * The consequence is not cosmetic: the OTP limiters key partly on the
 * client address, so an untrusted proxy collapses every customer into one
 * bucket and the first busy morning locks real people out of logging in.
 *
 * Both halves are asserted here, because both are deliberate. Trusting
 * nothing is the default and is correct for PHP reached directly; trusting
 * the load balancer is a deployment fact that TRUSTED_PROXIES carries. The
 * failure this guards against is neither choice being made.
 */
class TrustedProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = '10.0.0.5';

    private const CUSTOMER = '203.0.113.99';

    protected function tearDown(): void
    {
        // Static on the middleware, so it outlives the test that set it.
        TrustProxies::at([]);

        parent::tearDown();
    }

    private function throughProxy(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => self::PROXY,
            'HTTP_X_FORWARDED_FOR' => self::CUSTOMER,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->postJson('/api/v1/auth/send-otp', ['mobile' => '9811100011']);
    }

    public function test_an_untrusted_proxy_hides_the_customer(): void
    {
        TrustProxies::at([]);

        $this->throughProxy();

        /*
         * The documented default, asserted so that it is a choice rather
         * than an accident. Anything keying on the address is keying on the
         * proxy here, which is why TRUSTED_PROXIES exists.
         */
        $this->assertSame(self::PROXY, request()->ip());
        $this->assertFalse(request()->secure());
    }

    public function test_a_trusted_proxy_reveals_the_customer_and_the_scheme(): void
    {
        TrustProxies::at([self::PROXY]);

        $this->throughProxy();

        $this->assertSame(self::CUSTOMER, request()->ip());
        $this->assertTrue(
            request()->secure(),
            'TLS terminated at the proxy was not recognised',
        );
    }

    public function test_two_customers_behind_one_trusted_proxy_are_two_callers(): void
    {
        TrustProxies::at([self::PROXY]);

        $seen = [];

        foreach (['203.0.113.10', '203.0.113.99'] as $client) {
            $this->withServerVariables([
                'REMOTE_ADDR' => self::PROXY,
                'HTTP_X_FORWARDED_FOR' => $client,
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ])->postJson('/api/v1/auth/send-otp', ['mobile' => '9811100022']);

            $seen[] = request()->ip();
        }

        // The whole point: the per-address OTP limits count people, not
        // load balancers.
        $this->assertCount(2, array_unique($seen));
    }

    public function test_a_proxy_that_is_not_the_configured_one_is_not_believed(): void
    {
        TrustProxies::at(['10.0.0.9']);

        $this->throughProxy();

        /*
         * X-Forwarded-For is a request header like any other. If every
         * proxy were trusted, an application reachable outside its load
         * balancer would let any caller claim any address and step around
         * the per-address limits entirely.
         */
        $this->assertSame(self::PROXY, request()->ip());
    }
}
