<?php

namespace App\Domain\Ai;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A language-model engine was asked and did not produce an answer.
 *
 * Distinct from UnavailableFynnAiProvider, which means no engine is
 * configured at all. This means one is configured and failed this time: it
 * was down, it was too slow, or it answered with something that was not a
 * reply.
 *
 * Carries a canonical reason, never the engine's own words. The engine's
 * error bodies name internal hosts and ports, and none of that is a
 * customer's to read. The exception message is for FynnEdge's own
 * diagnosis and stays in the log.
 */
class AiEngineUnavailable extends RuntimeException
{
    /** The engine could not be reached at all. */
    public const UNREACHABLE = 'ai_engine_unreachable';

    /** The engine answered, but the model behind it is down or missing. */
    public const MODEL_UNAVAILABLE = 'ai_model_unavailable';

    /** No answer arrived in time. */
    public const TIMEOUT = 'ai_engine_timeout';

    /** The engine answered with an error of its own. */
    public const ENGINE_ERROR = 'ai_engine_error';

    /** The engine answered successfully, but not with a usable reply. */
    public const INVALID_RESPONSE = 'ai_engine_invalid_response';

    public function __construct(
        public readonly string $reason,
        string $diagnosis,
        ?Throwable $previous = null,
    ) {
        parent::__construct($diagnosis, 0, $previous);
    }

    /**
     * What a customer is told.
     *
     * The same few sentences whatever broke upstream: which part of the
     * engine failed is not something a customer can act on.
     */
    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::TIMEOUT => 'FynnAI took too long to answer. Please try again.',
            self::UNREACHABLE, self::MODEL_UNAVAILABLE => 'FynnAI is temporarily '
                .'unavailable. Please try again in a moment.',
            default => 'FynnAI could not answer right now. Please try again.',
        };
    }

    /**
     * The status FynnEdge answers with, as the gateway in front of the engine.
     */
    public function status(): int
    {
        return match ($this->reason) {
            self::TIMEOUT => Response::HTTP_GATEWAY_TIMEOUT,
            self::UNREACHABLE, self::MODEL_UNAVAILABLE => Response::HTTP_SERVICE_UNAVAILABLE,
            default => Response::HTTP_BAD_GATEWAY,
        };
    }
}
