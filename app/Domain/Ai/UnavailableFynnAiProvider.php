<?php

namespace App\Domain\Ai;

use App\Models\Conversation;
use RuntimeException;

/**
 * No assistant at all.
 *
 * Bound when FynnEdge is configured to expect a language model that has not
 * been provided. It answers nothing rather than quietly handing the question
 * to something else and letting the customer believe a model replied.
 */
class UnavailableFynnAiProvider implements FynnAiProvider
{
    public const REASON = 'ai_provider_not_configured';

    public const MESSAGE = 'FynnAI is not connected yet. Nothing has been '
        .'sent anywhere and no answer was generated.';

    public function isAvailable(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none';
    }

    public function isLanguageModel(): bool
    {
        return false;
    }

    public function generate(
        FynnAiContext $context,
        Conversation $conversation,
        string $message,
    ): AiReply {
        throw new RuntimeException('ai_unavailable');
    }
}
