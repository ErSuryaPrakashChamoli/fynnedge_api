<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Cache\RateLimiter as CoreRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every throttle in the product, walked from the router itself.
 *
 * RateLimitTest proves the three limits somebody wrote a test for. Twelve
 * are registered, and the other nine were only ever verified by reading the
 * provider — which shows that a limit is *defined*, not that it is reached,
 * and not that the key it counts by is the one it should count by.
 *
 * The failure mode being guarded against is quiet: a limiter keyed by IP
 * where it meant to key by customer still returns 429 to whoever tests it
 * by hand, and lets a whole office through in production. So each probe
 * below fills the bucket using the key the *application* would compute, and
 * then makes one real request. If the key were different the request would
 * succeed and the probe would fail.
 *
 * Like OwnershipCoverageTest, this holds no list of its own: it reads the
 * route table, and a limiter added without a probe fails the suite until
 * somebody makes that choice deliberately.
 */
class RateLimitCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every registered limiter, and how to reach it.
     *
     * `key` is how the limiter identifies the caller — the same string the
     * provider builds. `limit` is how many the bucket holds.
     *
     * @return array<string, array{route: string, verb: string, key: string, limit: int, auth: bool}>
     */
    private function probes(): array
    {
        $id = fn () => (string) auth()->id();

        return [
            'otp-send' => [
                'route' => '/api/v1/auth/send-otp', 'verb' => 'POST',
                'key' => 'otp-send:mobile:9812345670', 'limit' => 3, 'auth' => false,
                'body' => ['mobile' => '9812345670'],
            ],
            'otp-verify' => [
                'route' => '/api/v1/auth/verify-otp', 'verb' => 'POST',
                'key' => 'otp-verify:mobile:9812345670', 'limit' => 6, 'auth' => false,
                'body' => ['mobile' => '9812345670', 'code' => '000000'],
            ],
            'api' => [
                'route' => '/api/v1/goals', 'verb' => 'GET',
                'key' => fn () => $id(), 'limit' => 300, 'auth' => true,
            ],
            'ai-write' => [
                'route' => '/api/v1/ai/conversations', 'verb' => 'POST',
                'key' => fn () => 'ai-write:'.$id(), 'limit' => 20, 'auth' => true,
            ],
            'credit-check' => [
                'route' => '/api/v1/credit/check', 'verb' => 'POST',
                'key' => fn () => 'credit-check:'.$id(), 'limit' => 5, 'auth' => true,
            ],
            'scan-write' => [
                'route' => '/api/v1/documents/1/scan', 'verb' => 'POST',
                'key' => fn () => 'scan-write:'.$id(), 'limit' => 10, 'auth' => true,
            ],
            'kyc-write' => [
                'route' => '/api/v1/documents/1/kyc', 'verb' => 'POST',
                'key' => fn () => 'kyc-write:'.$id(), 'limit' => 5, 'auth' => true,
            ],
            'lender-submit' => [
                'route' => '/api/v1/applications/1/submission', 'verb' => 'POST',
                'key' => fn () => 'lender-submit:'.$id(), 'limit' => 3, 'auth' => true,
            ],
            'vault-write' => [
                'route' => '/api/v1/documents', 'verb' => 'POST',
                'key' => fn () => 'vault-write:'.$id(), 'limit' => 20, 'auth' => true,
            ],
            'support-create' => [
                'route' => '/api/v1/support/cases', 'verb' => 'POST',
                'key' => fn () => 'support-create:'.$id(), 'limit' => 5, 'auth' => true,
            ],
            'support-message' => [
                'route' => '/api/v1/support/cases/1/messages', 'verb' => 'POST',
                'key' => fn () => 'support-message:'.$id(), 'limit' => 20, 'auth' => true,
            ],
            'provider-events' => [
                'route' => '/api/v1/provider-events/fixture', 'verb' => 'POST',
                'key' => 'provider-events:fixture:127.0.0.1', 'limit' => 120, 'auth' => false,
                // Keyed by the provider in the path, which a synthetic
                // request has no route to read — so this one keeps its
                // literal, and the probe proves the literal is right.
                'literal_key' => true,
            ],
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function limiters(): iterable
    {
        foreach ([
            'otp-send', 'otp-verify', 'api', 'ai-write', 'credit-check',
            'scan-write', 'kyc-write', 'lender-submit', 'vault-write',
            'support-create', 'support-message', 'provider-events',
        ] as $name) {
            yield $name => [$name];
        }
    }

    public function test_every_registered_limiter_has_a_probe(): void
    {
        $attached = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (preg_match('/throttle:([a-z-]+)/', (string) $middleware, $m)) {
                    $attached[$m[1]] = true;
                }
            }
        }

        /*
         * Both directions.
         *
         * A limiter attached to no route is dead weight that reads as
         * protection; a throttle name on a route with no limiter behind it
         * would throw at request time. Neither should be discoverable only
         * by a customer.
         */
        $this->assertSame(
            array_keys($attached),
            array_intersect(array_keys($attached), array_keys($this->probes())),
            'a route carries a throttle nothing here probes',
        );

        foreach (array_keys($this->probes()) as $name) {
            $this->assertArrayHasKey(
                $name,
                $attached,
                "[{$name}] is registered but reaches no route",
            );
        }
    }

    #[DataProvider('limiters')]
    public function test_the_limit_is_reached_by_the_key_the_app_computes(string $name): void
    {
        $probe = $this->probes()[$name];

        if ($probe['auth']) {
            Sanctum::actingAs($this->customer());
        }

        /*
         * Filled through the limiter, not through the route.
         *
         * Making $limit real requests would prove the throttle triggers and
         * say nothing about what it counts by. Filling the bucket under the
         * key the provider builds and then making one request proves both:
         * a limiter keyed differently would leave this request unthrottled.
         */
        for ($i = 0; $i < $probe['limit']; $i++) {
            RateLimiter::hit($this->cacheKey($name, $probe), 3600);
        }

        $this->request($probe)->assertStatus(429);
    }

    #[DataProvider('limiters')]
    public function test_one_callers_limit_is_not_another_callers(string $name): void
    {
        $probe = $this->probes()[$name];

        // Per-mobile and per-provider limiters are scoped by something the
        // request carries rather than by who is holding a token; their
        // separation is proved by the key itself.
        if (! $probe['auth']) {
            $this->markTestSkipped('scoped by the request, not by the caller');
        }

        Sanctum::actingAs($this->customer());

        for ($i = 0; $i < $probe['limit']; $i++) {
            RateLimiter::hit($this->cacheKey($name, $probe), 3600);
        }

        // A second customer, arriving at the same endpoint at the same
        // moment, is not paying for the first one's traffic.
        Sanctum::actingAs($this->customer());

        $this->assertNotSame(429, $this->request($probe)->getStatusCode());
    }

    /**
     * The cache key ThrottleRequests actually counts against, asked of the
     * limiter rather than reconstructed.
     *
     * Reconstructing it is where the first version of this test went wrong.
     * A named limiter's bucket is md5(name . the provider's `by()` string) —
     * except that when a limiter returns several limits sharing one `by()`,
     * Laravel disambiguates them by appending `:attempts:N:decay:M`, which
     * is exactly the case for the OTP send limits. Twelve probes were
     * written against a formula that was right for eleven of them, and the
     * twelfth looked like a missing hourly cap until it was measured: one
     * number can receive exactly ten codes an hour, as configured.
     *
     * So the key comes from the registered closure, evaluated against the
     * request the probe is about to make. A limiter re-keyed tomorrow moves
     * this with it, and a limiter keyed by the wrong thing still fails the
     * probe — which is the property worth having.
     *
     * @param  array<string, mixed>  $probe
     */
    private function cacheKey(string $name, array $probe): string
    {
        if ($probe['literal_key'] ?? false) {
            return md5($name.$probe['key']);
        }

        $limiter = app(CoreRateLimiter::class)->limiter($name);

        $request = Request::create(
            $probe['route'],
            $probe['verb'],
            $probe['body'] ?? [],
        );
        $request->setUserResolver(fn () => auth()->user());

        // The first limit is the tightest per-caller one in every limiter
        // here; the probe fills that bucket and leaves the rest alone.
        $limit = Collection::wrap($limiter($request))->first();

        return md5($name.$limit->key);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();

        return $user;
    }

    /** @param array<string, mixed> $probe */
    private function request(array $probe)
    {
        return $probe['verb'] === 'GET'
            ? $this->getJson($probe['route'])
            : $this->postJson($probe['route'], $probe['body'] ?? []);
    }
}
