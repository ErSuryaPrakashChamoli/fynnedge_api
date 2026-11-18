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
 * so. It is given the conversation, the customer's first name, and — only
 * with the customer's consent — their financial context. Grounding a model
 * in a customer's figures is a decision about where their data goes, so
 * this adapter does not make it: it reads the answer the context builder
 * already took from the consent ledger (ConsentType::AiMemory, "FynnAI
 * grounding its answers in the customer's own figures") and sends the
 * figures only when that says yes.
 *
 * The context travels as its own field, never folded into the prompt, so
 * the engine can tell what the customer typed from what FynnEdge knows.
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
            text: $this->engine->chat(
                $this->prompt($conversation, $message),
                $this->permittedContext($context),
            ),
        );
    }

    /**
     * What of the customer's context may leave for the engine.
     *
     * All of it with current AI memory consent. Without it, only the first
     * name, so FynnAI can greet the customer by name. The consent covers
     * FynnAI reading the customer's financial profile, and a name is not a
     * figure. Everything else stays behind it: figures, goals, score,
     * applications, documents and memories. `memoryAllowed` is the consent
     * ledger's answer for the current wording, so a consent never given,
     * withdrawn, or given under older wording all send the name alone.
     *
     * `memory_allowed: false` travels with the name, so the engine knows
     * it was given a name and nothing else. Without it, a name-only
     * context would read as a customer with no income and no goals.
     *
     * @return array<string, mixed>
     */
    private function permittedContext(FynnAiContext $context): array
    {
        if ($context->memoryAllowed) {
            return $context->toArray();
        }

        $firstName = $context->customer['first_name'] ?? null;

        if (blank($firstName)) {
            return [];
        }

        return [
            'customer' => ['first_name' => $firstName],
            'memory_allowed' => false,
        ];
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
