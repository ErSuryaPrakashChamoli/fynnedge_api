<?php

namespace Tests\Feature\Api;

use App\Domain\Ai\AiEngineUnavailable;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * FynnAI answered by the FynnAI Engine: FastAPI in front of Ollama.
 *
 * The engine is faked at the HTTP boundary, so these run the real provider,
 * service, controller and database, and never reach a model.
 */
class AiChatTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE = 'http://fynnai.test';

    private const CHAT = self::ENGINE.'/ollama/chat';

    private const HEALTH = self::ENGINE.'/health';

    private const KEY = 'test-engine-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fynn_ai.provider' => 'fastapi',
            'services.fastapi.base_url' => self::ENGINE,
            'services.fastapi.api_key' => self::KEY,
            'services.fastapi.history_messages' => 10,
        ]);

        Http::preventStrayRequests();
    }

    /** A signed-in customer, with the profiles every sign-in creates. */
    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        return $user;
    }

    /** A signed-in customer with a name, a city and figures worth protecting. */
    private function customerWithFigures(): User
    {
        $user = $this->customer();
        $user->customerProfile->update(['full_name' => 'Rahul Sharma', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ]);

        return $user;
    }

    private function grantMemory(): void
    {
        $this->postJson('/api/v1/consents', ['type' => 'ai_memory'])->assertOk();
    }

    private function engineReplies(string $reply): void
    {
        Http::fake([self::CHAT => Http::response([
            'model' => 'llama3.2:latest',
            'reply' => $reply,
            'duration_ms' => 120,
        ])]);
    }

    // --- Answering ----------------------------------------------------------

    public function test_a_first_message_starts_a_conversation_and_returns_the_models_reply(): void
    {
        $user = $this->customer();
        $this->engineReplies('Hello! How can I help you today?');

        $response = $this->postJson('/api/v1/ai/chat', ['message' => 'Hello, how are you?']);

        $conversation = $user->conversations()->sole();
        $response->assertOk()
            // The shape the Flutter app reads. Adding a field is safe;
            // losing one of these breaks the app.
            ->assertJsonStructure(['data' => [
                'conversation_id', 'message_id', 'user_message', 'ai_response', 'at',
                'suggestions', 'actions', 'provider' => ['available', 'is_language_model', 'name'],
            ]])
            ->assertJsonPath('data.conversation_id', (string) $conversation->id)
            ->assertJsonPath('data.user_message', 'Hello, how are you?')
            ->assertJsonPath('data.ai_response', 'Hello! How can I help you today?')
            ->assertJsonPath('data.provider', [
                'available' => true,
                'is_language_model' => true,
                'name' => 'fynnai-engine',
            ]);

        $this->assertSame('Hello, how are you?', $conversation->title);
        $this->assertSame(
            [
                ['user', 'Hello, how are you?'],
                ['assistant', 'Hello! How can I help you today?'],
            ],
            $conversation->messages->map(fn ($m) => [$m->role, $m->content])->all(),
        );
        $this->assertSame(
            (string) $conversation->messages->last()->id,
            $response->json('data.message_id'),
        );

        // With nothing earlier, the engine receives exactly what was typed,
        // and no context: this customer has not consented to sharing any.
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::CHAT
            && $request->body() === '{"message":"Hello, how are you?","context":{}}');
    }

    public function test_the_engine_key_is_sent_with_every_request(): void
    {
        $this->customer();
        Http::fake([
            self::CHAT => Http::response(['model' => 'm', 'reply' => 'Hi.', 'duration_ms' => 1]),
            self::HEALTH => Http::response(['status' => 'healthy']),
        ]);

        $this->postJson('/api/v1/ai/chat', ['message' => 'Hello'])->assertOk();
        $response = $this->getJson('/api/v1/ai/status')->assertOk();

        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => ! $request->hasHeader('X-FynnAI-Key', self::KEY));

        // A server-to-server secret: the app never sees it.
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
    }

    public function test_the_history_sent_stops_at_its_character_budget(): void
    {
        $user = $this->customer();
        $conversation = $user->conversations()->create(['title' => 'Long']);
        // About 1,400 characters a turn: four fit in the 6,000 budget, five do not.
        foreach (range(1, 5) as $turn) {
            $conversation->messages()->create([
                'role' => $turn % 2 === 1 ? 'user' : 'assistant',
                'content' => "Turn {$turn} ".str_repeat('x', 1390),
            ]);
        }
        $this->engineReplies('Noted.');

        $this->postJson('/api/v1/ai/chat', [
            'message' => 'And now?',
            'conversation_id' => $conversation->id,
        ])->assertOk();

        $sent = Http::recorded()[0][0]['message'];
        $this->assertStringNotContainsString('Turn 1 ', $sent);
        $this->assertStringContainsString('User: Turn 3 ', $sent);
        $this->assertStringContainsString('User: Turn 5 ', $sent);
        $this->assertStringEndsWith('User: And now?', $sent);
    }

    // --- What the engine may know --------------------------------------------

    public function test_with_ai_memory_consent_the_customers_context_is_sent_beside_the_message(): void
    {
        $this->customerWithFigures();
        $this->grantMemory();
        $shownToCustomer = $this->getJson('/api/v1/ai/context')->assertOk()->json('data.context');
        $this->engineReplies('Based on your figures, here is what an EMI would look like.');

        $this->postJson('/api/v1/ai/chat', ['message' => 'Can I afford a home loan?'])->assertOk();

        $sent = Http::recorded()[0][0];
        // The context travels as its own field; the prompt is what was typed.
        $this->assertSame('Can I afford a home loan?', $sent['message']);
        // Exactly what the app shows the customer FynnAI can see.
        $this->assertSame($shownToCustomer, $sent['context']);
        $this->assertSame('Rahul', $sent['context']['customer']['first_name']);
        $this->assertSame(100000, $sent['context']['financial_profile']['monthly_income']);
        $this->assertSame(20000, $sent['context']['financial_profile']['existing_emi']);
        $this->assertTrue($sent['context']['memory_allowed']);
    }

    public function test_without_ai_memory_consent_no_personal_context_is_sent(): void
    {
        $this->customerWithFigures();
        $this->engineReplies('Generally, lenders look at your EMI to income ratio.');

        $this->postJson('/api/v1/ai/chat', ['message' => 'Can I afford a home loan?'])->assertOk();

        // An empty object, not an empty list, so the engine's schema accepts it.
        $this->assertSame(
            '{"message":"Can I afford a home loan?","context":{}}',
            Http::recorded()[0][0]->body(),
        );
    }

    public function test_after_ai_memory_consent_is_withdrawn_no_personal_context_is_sent(): void
    {
        $this->customerWithFigures();
        $this->grantMemory();
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();
        $this->engineReplies('Generally, lenders look at your EMI to income ratio.');

        $this->postJson('/api/v1/ai/chat', ['message' => 'Can I afford a home loan?'])->assertOk();

        $this->assertSame(
            '{"message":"Can I afford a home loan?","context":{}}',
            Http::recorded()[0][0]->body(),
        );
    }

    public function test_a_follow_up_sends_the_earlier_turns_with_the_new_message(): void
    {
        $user = $this->customer();
        Http::fake([self::CHAT => Http::sequence()
            ->push(['model' => 'llama3.2:latest', 'reply' => 'Teal is a lovely colour.', 'duration_ms' => 1])
            ->push(['model' => 'llama3.2:latest', 'reply' => 'Your favourite colour is teal.', 'duration_ms' => 1]),
        ]);

        $id = $this->postJson('/api/v1/ai/chat', ['message' => 'My favourite colour is teal.'])
            ->assertOk()
            ->json('data.conversation_id');

        $this->postJson('/api/v1/ai/chat', [
            'message' => 'What is my favourite colour?',
            'conversation_id' => $id,
        ])
            ->assertOk()
            ->assertJsonPath('data.conversation_id', $id)
            ->assertJsonPath('data.ai_response', 'Your favourite colour is teal.');

        $this->assertSame(4, $user->conversations()->sole()->messages()->count());

        $sent = Http::recorded()[1][0]['message'];
        $this->assertStringContainsString(
            "User: My favourite colour is teal.\nAssistant: Teal is a lovely colour.",
            $sent,
        );
        $this->assertStringEndsWith('User: What is my favourite colour?', $sent);
    }

    public function test_only_the_most_recent_messages_are_sent_as_history(): void
    {
        config(['services.fastapi.history_messages' => 2]);
        $user = $this->customer();
        $conversation = $user->conversations()->create(['title' => 'Earlier']);
        $conversation->messages()->createMany([
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'First answer'],
            ['role' => 'user', 'content' => 'Second question'],
            ['role' => 'assistant', 'content' => 'Second answer'],
        ]);
        $this->engineReplies('Third answer');

        $this->postJson('/api/v1/ai/chat', [
            'message' => 'Third question',
            'conversation_id' => $conversation->id,
        ])->assertOk();

        Http::assertSent(fn (Request $request) => str_contains(
            $request['message'],
            "User: Second question\nAssistant: Second answer",
        ) && ! str_contains($request['message'], 'First'));
    }

    public function test_with_history_turned_off_only_the_new_message_is_sent(): void
    {
        config(['services.fastapi.history_messages' => 0]);
        $user = $this->customer();
        $conversation = $user->conversations()->create(['title' => 'Earlier']);
        $conversation->messages()->createMany([
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'First answer'],
        ]);
        $this->engineReplies('Second answer');

        $this->postJson('/api/v1/ai/chat', [
            'message' => 'Second question',
            'conversation_id' => $conversation->id,
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request->body() === '{"message":"Second question","context":{}}');
    }

    public function test_the_existing_messages_endpoint_answers_with_the_engine(): void
    {
        $user = $this->customer();
        $conversation = $user->conversations()->create(['title' => 'Earlier']);
        $this->engineReplies('Hi there.');

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['text' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.text', 'Hi there.')
            ->assertJsonPath('data.provider.is_language_model', true);
    }

    // --- Who may ask --------------------------------------------------------

    #[TestWith(['POST', '/api/v1/ai/chat'])]
    #[TestWith(['GET', '/api/v1/ai/status'])]
    public function test_an_anonymous_caller_is_refused_401(string $method, string $uri): void
    {
        Http::fake([
            self::CHAT => Http::response(['model' => 'm', 'reply' => 'unused', 'duration_ms' => 1]),
            self::HEALTH => Http::response(['status' => 'healthy']),
        ]);

        $this->json($method, $uri, ['message' => 'Hello'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        Http::assertNothingSent();
    }

    public function test_another_customers_conversation_is_not_found_404(): void
    {
        $theirs = User::factory()->create()->conversations()->create(['title' => 'Theirs']);
        $this->customer();
        $this->engineReplies('unused');

        $this->postJson('/api/v1/ai/chat', [
            'message' => 'Hello',
            'conversation_id' => $theirs->id,
        ])
            ->assertNotFound()
            ->assertJsonPath('message', 'That conversation could not be found.');

        $this->assertSame(0, $theirs->messages()->count());
        Http::assertNothingSent();
    }

    public function test_chat_is_limited_with_the_other_ai_writes_429(): void
    {
        $this->customer();
        $this->engineReplies('Hello.');

        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/ai/chat', ['message' => 'Hello'])->assertOk();
        }

        $this->postJson('/api/v1/ai/chat', ['message' => 'Hello'])->assertTooManyRequests();

        Http::assertSentCount(20);
    }

    // --- What is refused ----------------------------------------------------

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidChats(): iterable
    {
        yield 'no message' => [[], 'message', 'The message field is required.'];
        yield 'empty message' => [['message' => ''], 'message', 'The message field is required.'];
        yield 'only spaces' => [['message' => '   '], 'message', 'The message field is required.'];
        yield 'not text' => [['message' => ['Hello']], 'message', 'The message field must be a string.'];
        yield 'too long' => [
            ['message' => str_repeat('a', 2001)],
            'message',
            'The message field must not be greater than 2000 characters.',
        ];
        yield 'conversation id not a number' => [
            ['message' => 'Hello', 'conversation_id' => 'abc'],
            'conversation_id',
            'The conversation id field must be an integer.',
        ];
    }

    #[DataProvider('invalidChats')]
    public function test_an_invalid_chat_is_refused_422(array $body, string $field, string $error): void
    {
        $user = $this->customer();
        $this->engineReplies('unused');

        $this->postJson('/api/v1/ai/chat', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $error]);

        $this->assertSame(0, $user->conversations()->count());
        Http::assertNothingSent();
    }

    // --- When the engine cannot answer --------------------------------------

    /** @return iterable<string, array{Closure, int, string, string}> */
    public static function engineFailures(): iterable
    {
        $unavailable = 'FynnAI is temporarily unavailable. Please try again in a moment.';
        $slow = 'FynnAI took too long to answer. Please try again.';
        $broken = 'FynnAI could not answer right now. Please try again.';

        yield 'engine unreachable' => [
            fn () => Http::failedConnection(),
            503, AiEngineUnavailable::UNREACHABLE, $unavailable,
        ];
        yield 'engine connect timeout' => [
            fn () => Http::failedConnection('cURL error 28: Connection timed out after 3001 milliseconds'),
            503, AiEngineUnavailable::UNREACHABLE, $unavailable,
        ];
        yield 'engine too slow' => [
            fn () => Http::failedConnection(
                'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received'
            ),
            504, AiEngineUnavailable::TIMEOUT, $slow,
        ];
        yield 'ollama down behind the engine' => [
            fn () => Http::response(['detail' => 'Cannot connect to Ollama at http://10.0.0.9:11434'], 503),
            503, AiEngineUnavailable::MODEL_UNAVAILABLE, $unavailable,
        ];
        yield 'ollama too slow behind the engine' => [
            fn () => Http::response(['detail' => 'Ollama at http://10.0.0.9:11434 timed out'], 504),
            504, AiEngineUnavailable::TIMEOUT, $slow,
        ];
        yield 'engine error' => [
            fn () => Http::response(['detail' => 'Internal Server Error at 10.0.0.9'], 500),
            502, AiEngineUnavailable::ENGINE_ERROR, $broken,
        ];
        yield 'engine refuses the key' => [
            fn () => Http::response(['detail' => 'Invalid X-FynnAI-Key'], 401),
            502, AiEngineUnavailable::ENGINE_ERROR, $broken,
        ];
        yield 'not json' => [
            fn () => Http::response('<html>Bad Gateway 10.0.0.9</html>', 200),
            502, AiEngineUnavailable::INVALID_RESPONSE, $broken,
        ];
        yield 'no reply field' => [
            fn () => Http::response(['model' => 'llama3.2:latest', 'duration_ms' => 1]),
            502, AiEngineUnavailable::INVALID_RESPONSE, $broken,
        ];
        yield 'blank reply' => [
            fn () => Http::response(['model' => 'llama3.2:latest', 'reply' => '   ', 'duration_ms' => 1]),
            502, AiEngineUnavailable::INVALID_RESPONSE, $broken,
        ];
    }

    #[DataProvider('engineFailures')]
    public function test_an_engine_failure_is_reported_without_its_details_and_saves_nothing(
        Closure $engine,
        int $status,
        string $reason,
        string $message,
    ): void {
        $user = $this->customer();
        Http::fake([self::CHAT => $engine()]);

        $response = $this->postJson('/api/v1/ai/chat', ['message' => 'Hello']);

        $response->assertStatus($status)
            ->assertExactJson(['message' => $message, 'reason' => $reason]);

        // A failed first message leaves no empty conversation behind.
        $this->assertSame(0, $user->conversations()->count());
        $this->assertDatabaseCount('conversation_messages', 0);
    }

    public function test_a_failure_in_an_existing_conversation_keeps_it_and_records_nothing_503(): void
    {
        $user = $this->customer();
        $conversation = $user->conversations()->create(['title' => 'Earlier']);
        Http::fake([self::CHAT => Http::failedConnection()]);

        $this->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", ['text' => 'Hello'])
            ->assertServiceUnavailable()
            ->assertJsonPath('reason', AiEngineUnavailable::UNREACHABLE);

        $this->assertModelExists($conversation);
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_an_engine_refusal_that_quotes_the_request_is_logged_without_it_502(): void
    {
        $this->customerWithFigures();
        $this->grantMemory();
        // FastAPI's validation refusal echoes the rejected input back.
        Http::fake([self::CHAT => Http::response(['detail' => [[
            'type' => 'missing',
            'loc' => ['body', 'message'],
            'msg' => 'Field required',
            'input' => ['context' => ['customer' => ['first_name' => 'Rahul', 'city' => 'Pune']]],
        ]]], 422)]);
        Log::spy();

        $this->postJson('/api/v1/ai/chat', ['message' => 'My salary is 100000'])
            ->assertStatus(502)
            ->assertJsonPath('reason', AiEngineUnavailable::ENGINE_ERROR);

        Log::shouldHaveReceived('warning')->once()->with(
            'FynnAI engine did not answer: The engine answered 422.',
            ['reason' => AiEngineUnavailable::ENGINE_ERROR, 'status' => 422],
        );
    }

    #[TestWith(['services.fastapi.base_url'])]
    #[TestWith(['services.fastapi.api_key'])]
    public function test_an_engine_with_no_address_or_no_key_is_reported_as_not_connected_501(string $setting): void
    {
        config([$setting => null]);
        $user = $this->customer();
        Http::fake([
            self::CHAT => Http::response(['model' => 'm', 'reply' => 'unused', 'duration_ms' => 1]),
            self::HEALTH => Http::response(['status' => 'healthy']),
        ]);

        $this->postJson('/api/v1/ai/chat', ['message' => 'Hello'])
            ->assertStatus(501)
            ->assertJsonPath('data.reason', 'ai_provider_not_configured')
            ->assertJsonPath('data.provider.available', false);
        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('data.online', false);

        $this->assertSame(0, $user->conversations()->count());
        Http::assertNothingSent();
    }

    // --- Status -------------------------------------------------------------

    public function test_status_is_online_when_the_engine_passes_its_health_check(): void
    {
        $this->customer();
        Http::fake([self::HEALTH => Http::response(['status' => 'healthy'])]);

        $response = $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('data.online', true)
            ->assertJsonPath('data.provider.name', 'fynnai-engine');

        // Where the engine lives is the server's business, not the app's.
        $this->assertStringNotContainsString('fynnai.test', $response->getContent());
        Http::assertSent(fn (Request $request) => $request->url() === self::HEALTH);
    }

    /** @return iterable<string, array{Closure}> */
    public static function unhealthyEngines(): iterable
    {
        yield 'unreachable' => [fn () => Http::failedConnection()];
        yield 'erroring' => [fn () => Http::response(['detail' => 'boom'], 500)];
        yield 'not healthy' => [fn () => Http::response(['status' => 'starting'])];
    }

    #[DataProvider('unhealthyEngines')]
    public function test_status_is_offline_when_the_engine_fails_its_health_check(Closure $engine): void
    {
        $this->customer();
        Http::fake([self::HEALTH => $engine()]);

        $this->getJson('/api/v1/ai/status')
            ->assertOk()
            ->assertJsonPath('data.online', false);
    }
}
