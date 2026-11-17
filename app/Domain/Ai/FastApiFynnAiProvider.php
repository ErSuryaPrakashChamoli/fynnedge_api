<?php

namespace App\Domain\Ai;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Ai\FastApiChatService;
use Illuminate\Support\Str;

/**
 * FynnAI answered by a language model: the FynnAI Engine, a FastAPI service
 * in front of a self-hosted Ollama model.
 *
 * Unlike the deterministic assistant this is a language model, and it says
 * so. It is given the conversation and nothing else. The customer's
 * financial context is deliberately not sent: grounding a model in a
 * customer's figures is a decision about where their data goes, and it
 * belongs with consent rather than inside an adapter.
 *
 * The engine keeps no conversation and accepts a single message, so the
 * earlier turns of FynnEdge's own stored transcript are folded into that
 * message. Bounded by count and by length, because a small local model's
 * context window is short and an over-long prompt is truncated silently at
 * the far end.
 */
class FastApiFynnAiProvider implements RemoteFynnAiProvider
{
    /** Longest single earlier turn carried into the prompt. */
    public const MAX_TURN_CHARACTERS = 1500;

    /** All earlier turns together. */
    public const MAX_HISTORY_CHARACTERS = 6000;

    public function __construct(private readonly FastApiChatService $engine) {}

    public function isAvailable(): bool
    {
        return $this->engine->isConfigured();
    }

    public function isReachable(): bool
    {
        return $this->engine->isHealthy();
    }

    public function name(): string
    {
        return 'fynnai-engine';
    }

    public function isLanguageModel(): bool
    {
        return true;
    }

    /**
     * @throws AiEngineUnavailable
     */
    public function generate(
        FynnAiContext $context,
        Conversation $conversation,
        string $message,
    ): AiReply {
        return new AiReply(
            text: $this->engine->chat($this->prompt($conversation, $message)),
        );
    }

    /**
     * The one message the engine receives.
     *
     * With no earlier turns it is exactly what the customer typed. Otherwise
     * the earlier turns come first, labelled by speaker, and the new message
     * last.
     */
    private function prompt(Conversation $conversation, string $message): string
    {
        $history = $this->history($conversation);

        if ($history === []) {
            return $message;
        }

        return "Here is the conversation so far:\n\n"
            .implode("\n", $history)
            ."\n\nReply to the user's latest message below, using the "
            ."conversation above for context.\n\n"
            .'User: '.$message;
    }

    /**
     * The most recent stored turns, oldest first.
     *
     * Read before the new message is stored, so the conversation is exactly
     * what the customer had seen when they wrote it.
     *
     * @return list<string>
     */
    private function history(Conversation $conversation): array
    {
        $limit = max(0, (int) config('services.fastapi.history_messages', 10));

        if ($limit === 0 || ! $conversation->exists) {
            return [];
        }

        $recent = $conversation->messages()
            ->reorder()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'content']);

        $turns = [];
        $budget = self::MAX_HISTORY_CHARACTERS;

        foreach ($recent as $turn) {
            $line = ($turn->role === ConversationMessage::ROLE_ASSISTANT ? 'Assistant: ' : 'User: ')
                .Str::limit(trim($turn->content), self::MAX_TURN_CHARACTERS);

            $budget -= mb_strlen($line);

            if ($budget < 0) {
                break;
            }

            $turns[] = $line;
        }

        return array_reverse($turns);
    }
}
