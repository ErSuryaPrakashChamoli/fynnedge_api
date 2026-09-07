<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every customer-scoped endpoint must refuse an anonymous caller. A single
 * route left off the auth group is a data leak, so they are checked as a set
 * rather than one at a time.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Drops the cached guard so the next request re-resolves its token. */
    private function forgetAuth(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /** @return list<array{string, string}> */
    public static function protectedEndpoints(): array
    {
        return [
            ['GET', '/api/v1/profile'],
            ['PUT', '/api/v1/profile'],
            ['GET', '/api/v1/financial-profile'],
            ['PUT', '/api/v1/financial-profile'],
            ['GET', '/api/v1/fynn-score'],
            ['GET', '/api/v1/goals'],
            ['POST', '/api/v1/goals'],
            ['GET', '/api/v1/goals/1'],
            ['PUT', '/api/v1/goals/1'],
            ['DELETE', '/api/v1/goals/1'],
            ['GET', '/api/v1/home'],
            ['GET', '/api/v1/products'],
            ['GET', '/api/v1/products/prod_bl_a'],
            ['POST', '/api/v1/compare'],
            ['GET', '/api/v1/documents'],
            ['GET', '/api/v1/documents/limits'],
            ['POST', '/api/v1/documents'],
            ['GET', '/api/v1/documents/1'],
            ['GET', '/api/v1/documents/1/download'],
            ['DELETE', '/api/v1/documents/1'],
            ['GET', '/api/v1/documents/1/scan'],
            ['POST', '/api/v1/documents/1/scan'],
            ['GET', '/api/v1/consents'],
            ['POST', '/api/v1/consents'],
            ['POST', '/api/v1/consents/ai_memory/withdraw'],
            ['GET', '/api/v1/consents/history'],
            ['GET', '/api/v1/applications'],
            ['GET', '/api/v1/applications/1'],
            ['POST', '/api/v1/applications'],
            ['PUT', '/api/v1/applications/1'],
            ['POST', '/api/v1/applications/1/submit'],
            ['POST', '/api/v1/applications/1/cancel'],
            ['GET', '/api/v1/documents'],
            ['POST', '/api/v1/documents'],
            ['DELETE', '/api/v1/documents/doc_1'],
            ['GET', '/api/v1/notifications'],
            ['POST', '/api/v1/notifications/read-all'],
            ['GET', '/api/v1/ai/context'],
            ['GET', '/api/v1/ai/conversations'],
            ['POST', '/api/v1/ai/conversations'],
            ['GET', '/api/v1/ai/conversations/1'],
            ['POST', '/api/v1/ai/conversations/1/messages'],
            ['DELETE', '/api/v1/ai/conversations/1'],
            ['GET', '/api/v1/ai/memory'],
            ['DELETE', '/api/v1/ai/memory'],
            ['DELETE', '/api/v1/ai/memory/1'],
            ['POST', '/api/v1/auth/logout'],
            ['GET', '/api/v1/auth/me'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_it_refuses_an_anonymous_caller(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_only_the_otp_endpoints_are_public(): void
    {
        $this->postJson('/api/v1/auth/send-otp', ['mobile' => '9876543210'])
            ->assertOk();
    }

    public function test_a_token_authenticates_a_request(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.mobile', $user->mobile);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();

        $phone = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$phone}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The guard caches the resolved user for the lifetime of the app
        // instance, so it has to be forgotten between requests or the second
        // call would pass on a stale identity rather than the token.
        $this->forgetAuth();

        // The phone's token is dead...
        $this->withHeader('Authorization', "Bearer {$phone}")
            ->getJson('/api/v1/profile')
            ->assertStatus(401);

        $this->forgetAuth();

        // ...but the tablet is still signed in.
        $this->withHeader('Authorization', "Bearer {$tablet}")
            ->getJson('/api/v1/profile')
            ->assertOk();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();
        $user->ensureProfiles();

        $token = $user->createToken('phone', ['*'], now()->subDay());

        $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")
            ->getJson('/api/v1/profile')
            ->assertStatus(401);
    }

    public function test_a_garbage_token_is_refused(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->getJson('/api/v1/profile')
            ->assertStatus(401);
    }

    public function test_one_customers_token_never_returns_another_customers_data(): void
    {
        $alice = User::factory()->create(['mobile' => '9111111111']);
        $alice->ensureProfiles();
        $alice->customerProfile->update(['full_name' => 'Alice']);

        $bob = User::factory()->create(['mobile' => '9222222222']);
        $bob->ensureProfiles();
        $bob->customerProfile->update(['full_name' => 'Bob']);

        $token = $alice->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Alice')
            ->assertJsonPath('data.mobile', '9111111111');
    }

    public function test_an_unauthenticated_call_returns_401_even_without_an_accept_header(): void
    {
        // A caller that forgets `Accept: application/json` must still be told
        // it is unauthenticated. This previously resolved a non-existent
        // `login` route and returned a 500 with a stack trace.
        $this->call('GET', '/api/v1/home')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_an_unauthenticated_call_never_leaks_a_stack_trace(): void
    {
        $body = $this->call('GET', '/api/v1/financial-summary')
            ->assertStatus(401)
            ->getContent();

        $this->assertStringNotContainsString('vendor/laravel', $body);
        $this->assertStringNotContainsString('"trace"', $body);
    }
}
