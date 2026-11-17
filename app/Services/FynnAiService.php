<?php

namespace App\Services;

use App\Domain\Ai\AiReply;
use App\Domain\Ai\FynnAiContextBuilder;
use App\Domain\Ai\FynnAiProvider;
use App\Domain\Ai\MemoryPolicy;
use App\Domain\Consent\ConsentType;
use App\Models\AiMemory;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The conversation, its memory, and the boundary to whatever answers.
 */
class FynnAiService
{
    public function __construct(
        private readonly FynnAiContextBuilder $contexts,
        private readonly FynnAiProvider $provider,
        private readonly ConsentService $consents,
    ) {}

    public function startConversation(User $user, ?string $title = null): Conversation
    {
        return $user->conversations()->create([
            'title' => $this->title($title),
        ]);
    }

    /**
     * Records the customer's message, answers it, and records the answer.
     *
     * The context is rebuilt from the authenticated user every time. Nothing
     * in the message is read before that happens, so no wording a customer
     * chooses can change what is loaded.
     */
    public function reply(User $user, Conversation $conversation, string $message): ConversationMessage
    {
        if (! $this->provider->isAvailable()) {
            throw new RuntimeException('ai_unavailable');
        }

        $context = $this->contexts->build($user);

        /*
         * Answered before anything is written, and outside the transaction.
         *
         * A language model can take seconds to reply, and a transaction must
         * not be held open across a network call. It also means a failed
         * answer records nothing — the customer's message is not left in
         * the transcript with no reply after it — and the provider reads the
         * conversation as it stood when the customer wrote this.
         */
        $reply = $this->provider->generate($context, $conversation, $message);

        return DB::transaction(function () use ($conversation, $message, $reply) {
            $conversation->messages()->create([
                'role' => ConversationMessage::ROLE_USER,
                'content' => $message,
            ]);

            $stored = $conversation->messages()->create([
                'role' => ConversationMessage::ROLE_ASSISTANT,
                'content' => $reply->text,
            ]);

            // A conversation's first exchange names it, so a list of them
            // reads as something other than "Conversation, Conversation".
            if ($conversation->messages()->count() === 2) {
                $conversation->update(['title' => $this->title($message)]);
            }

            $conversation->touch();

            // Carried on the model for the response, not persisted: the
            // suggestions and actions belong to this answer, not to history.
            $stored->setAttribute('reply', $reply);

            return $stored;
        });
    }

    /**
     * Answers one message, in a conversation the customer names or a new one.
     *
     * The single-call path, for a client that wants an answer without first
     * creating a conversation. A new conversation whose first message could
     * not be answered is removed again, so a failure does not leave an empty
     * "New conversation" in the customer's list.
     */
    public function chat(User $user, string $message, ?Conversation $conversation = null): ConversationMessage
    {
        if ($conversation !== null) {
            return $this->reply($user, $conversation, $message);
        }

        $conversation = $this->startConversation($user);

        try {
            return $this->reply($user, $conversation, $message);
        } catch (Throwable $e) {
            $conversation->delete();

            throw $e;
        }
    }

    /** @return array{suggestions: list<string>, actions: list<array<string, string>>} */
    public function presentation(ConversationMessage $message): array
    {
        $reply = $message->getAttribute('reply');

        return $reply instanceof AiReply
            ? ['suggestions' => $reply->suggestions, 'actions' => $reply->actions]
            : ['suggestions' => [], 'actions' => []];
    }

    /**
     * A short, readable name for a conversation.
     *
     * Taken from what the customer actually asked, so a list of them is
     * navigable rather than a column of identical labels.
     */
    private function title(?string $from): string
    {
        $text = trim((string) $from);

        return $text === ''
            ? 'New conversation'
            : Str::limit(preg_replace('/\s+/', ' ', $text) ?? $text, 110);
    }

    // --- Memory -------------------------------------------------------------

    /**
     * Remembers one thing, if the customer has allowed it and the policy
     * lists the key.
     *
     * Two gates, not one: consent says whether FynnEdge may remember
     * anything, and the policy says what "anything" can be.
     */
    public function remember(
        User $user,
        string $key,
        string $value,
        string $source,
    ): AiMemory {
        if (! $this->mayRemember($user)) {
            throw new RuntimeException('memory_disabled');
        }

        if (! MemoryPolicy::allows($key)) {
            throw new RuntimeException('memory_not_allowed');
        }

        return $user->aiMemories()->updateOrCreate(
            ['key' => $key],
            [
                'value' => Str::limit($value, MemoryPolicy::MAX_VALUE_LENGTH, ''),
                'source' => $source,
            ],
        );
    }

    public function mayRemember(User $user): bool
    {
        return $this->consents->hasCurrentConsent($user, ConsentType::AiMemory);
    }

    /**
     * Everything remembered — or nothing, once consent is gone.
     *
     * @return Collection<int, AiMemory>
     */
    public function memories(User $user)
    {
        return $user->aiMemories()->orderBy('key')->get();
    }

    public function forget(User $user, AiMemory $memory): void
    {
        $memory->delete();
    }

    public function forgetAll(User $user): int
    {
        return $user->aiMemories()->delete();
    }
}
