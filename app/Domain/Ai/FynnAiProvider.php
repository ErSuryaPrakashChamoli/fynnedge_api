<?php

namespace App\Domain\Ai;

use App\Models\Conversation;

/**
 * Whatever answers a customer's question.
 *
 * The domain names no vendor. A hosted model, a self-hosted one, or the
 * deterministic assistant FynnEdge ships today all sit behind this.
 */
interface FynnAiProvider
{
    public function isAvailable(): bool;

    /** How this provider identifies itself. */
    public function name(): string;

    /**
     * False for anything that is not a language model.
     *
     * Surfaced to the customer: an assistant built from fixed rules is
     * useful and honest, and calling it AI when it is not would be neither.
     */
    public function isLanguageModel(): bool;

    public function generate(
        FynnAiContext $context,
        Conversation $conversation,
        string $message,
    ): AiReply;
}
