<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiEngineUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response as HttpStatus;
use Throwable;

/**
 * The HTTP boundary to the FynnAI Engine: FastAPI in front of Ollama.
 *
 * Everything that knows the engine's address, paths and field names lives
 * here. What leaves this class is a reply or an AiEngineUnavailable with a
 * canonical reason — never a raw response, an engine error body, or a
 * transport exception.
 *
 * The engine is stateless. POST /ollama/chat takes one `message`, the
 * customer's `context` (empty unless they have consented to it), and an
 * optional `model`, and returns `{model, reply, duration_ms}`. It keeps no
 * conversation, so any history has to travel inside that one message.
 *
 * Every request carries the shared key in the X-FynnAI-Key header. The key
 * is read from configuration only and never appears in a log or exception.
 */
class FastApiChatService
{
    public const CHAT_PATH = '/ollama/chat';

    public const HEALTH_PATH = '/health';

    /** Where the engine expects the shared key. */
    public const KEY_HEADER = 'X-FynnAI-Key';

    /** A health check is not a generation, and should answer at once. */
    private const HEALTH_TIMEOUT_SECONDS = 3;

    /**
     * Whether an engine is configured: an address, and the key it requires.
     *
     * An address without a key is not half-configured. Every request would
     * be refused, so it is reported as not connected rather than as an
     * engine that is down.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.fastapi.base_url'))
            && filled(config('services.fastapi.api_key'));
    }

    /**
     * Sends one message to the model and returns its reply.
     *
     * Not retried: a generation is expensive and not idempotent, and a retry
     * after a timeout would double a wait the customer is already in.
     *
     * @param  array<string, mixed>  $context  what the customer has allowed FynnAI to see, or nothing
     *
     * @throws AiEngineUnavailable
     */
    public function chat(string $message, array $context = []): string
    {
        if (! $this->isConfigured()) {
            throw $this->failure(AiEngineUnavailable::UNREACHABLE, 'No engine address or key is configured.');
        }

        $payload = [
            'message' => $message,

            // Always a JSON object. An empty PHP array encodes as `[]`, which
            // an engine expecting a mapping would refuse as the wrong type.
            'context' => $context === [] ? new stdClass : $context,
        ];

        if (filled($model = config('services.fastapi.model'))) {
            $payload['model'] = $model;
        }

        try {
            $response = $this->client()
                ->timeout((int) config('services.fastapi.timeout', 15))
                ->post(self::CHAT_PATH, $payload);
        } catch (ConnectionException $e) {
            throw $this->connectionFailure($e);
        }

        if (! $response->successful()) {
            throw $this->responseFailure($response);
        }

        $reply = $response->json('reply');

        if (! is_string($reply) || trim($reply) === '') {
            throw $this->failure(
                AiEngineUnavailable::INVALID_RESPONSE,
                'The engine answered without a usable reply.',
                ['status' => $response->status(), 'body' => Str::limit($response->body(), 300)],
            );
        }

        return trim($reply);
    }

    /**
     * Whether the engine answers its own health check right now.
     *
     * The engine's process only: a healthy engine can still have no model
     * behind it, which shows up as a failed chat rather than here.
     */
    public function isHealthy(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->client()
                ->timeout(self::HEALTH_TIMEOUT_SECONDS)
                ->get(self::HEALTH_PATH);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('status') === 'healthy';
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.fastapi.base_url'))
            ->withHeaders([self::KEY_HEADER => (string) config('services.fastapi.api_key')])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('services.fastapi.connect_timeout', 3));
    }

    /**
     * No response at all.
     *
     * cURL reports both a connect timeout and a response timeout as error
     * 28. Only "Operation timed out" means the engine was reached and was
     * too slow; a connection or DNS timeout means it was never reached.
     */
    private function connectionFailure(ConnectionException $e): AiEngineUnavailable
    {
        $reason = str_contains($e->getMessage(), 'Operation timed out')
            ? AiEngineUnavailable::TIMEOUT
            : AiEngineUnavailable::UNREACHABLE;

        return $this->failure($reason, 'The engine did not respond.', ['error' => $e->getMessage()], $e);
    }

    /**
     * An error status from the engine.
     *
     * The engine answers 503 when it cannot reach Ollama and 404 when the
     * model is not installed; both mean the model is not there to answer.
     * Its 504 is Ollama timing out behind it.
     *
     * The body is logged for diagnosis except on a 422. That is the engine
     * refusing the request it was sent, and FastAPI's refusal quotes the
     * rejected input back — the customer's message and financial context.
     */
    private function responseFailure(Response $response): AiEngineUnavailable
    {
        $reason = match ($response->status()) {
            404, 503 => AiEngineUnavailable::MODEL_UNAVAILABLE,
            504 => AiEngineUnavailable::TIMEOUT,
            default => AiEngineUnavailable::ENGINE_ERROR,
        };

        $details = ['status' => $response->status()];

        if ($response->status() !== HttpStatus::HTTP_UNPROCESSABLE_ENTITY) {
            $details['body'] = Str::limit($response->body(), 300);
        }

        return $this->failure($reason, "The engine answered {$response->status()}.", $details);
    }

    /**
     * Logs the diagnosis for an operator and builds what callers receive.
     *
     * The customer's message is never logged: it is theirs, and the reason
     * and status are enough to find what broke.
     *
     * @param  array<string, mixed>  $details
     */
    private function failure(
        string $reason,
        string $diagnosis,
        array $details = [],
        ?Throwable $previous = null,
    ): AiEngineUnavailable {
        Log::warning('FynnAI engine did not answer: '.$diagnosis, ['reason' => $reason] + $details);

        return new AiEngineUnavailable($reason, $diagnosis, $previous);
    }
}
