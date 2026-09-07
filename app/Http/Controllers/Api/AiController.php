<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ai\FynnAiContextBuilder;
use App\Domain\Ai\FynnAiProvider;
use App\Domain\Ai\UnavailableFynnAiProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendAiMessageRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\FynnAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * FynnAI.
 *
 * Conversations belong to the authenticated customer and are resolved
 * through their own relation, so another customer's id is not found. The
 * context handed to the assistant is built from that same user and from
 * nothing in the request body.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly FynnAiService $ai,
        private readonly FynnAiContextBuilder $contexts,
        private readonly FynnAiProvider $provider,
    ) {}

    /**
     * What FynnAI can see about this customer.
     *
     * Exposed so the app can show the customer exactly that, and so a test
     * can assert its contents rather than guessing at a prompt.
     */
    public function context(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'context' => $this->contexts->build($request->user())->toArray(),
                'provider' => $this->providerDescription(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $conversations = $request->user()->conversations()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $conversations->map(fn (Conversation $c) => [
                'id' => (string) $c->id,
                'title' => $c->title,
                'created_at' => $c->created_at?->toIso8601String(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $conversation = $this->ai->startConversation(
            $request->user(),
            $request->input('title'),
        );

        return response()->json(
            ['data' => $this->conversationArray($conversation)],
            Response::HTTP_CREATED,
        );
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        return response()->json([
            'data' => $this->conversationArray(
                $this->owned($request, $conversation)->load('messages')
            ),
        ]);
    }

    public function destroy(Request $request, string $conversation): JsonResponse
    {
        $this->owned($request, $conversation)->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Answers one message.
     *
     * With no assistant configured this answers 501 rather than inventing a
     * reply or quietly handing the question to something the customer was
     * not told about.
     */
    public function send(
        SendAiMessageRequest $request,
        string $conversation,
    ): JsonResponse {
        $model = $this->owned($request, $conversation);

        try {
            $message = $this->ai->reply(
                $request->user(),
                $model,
                $request->validated('text'),
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        $presentation = $this->ai->presentation($message);

        return response()->json([
            'data' => array_merge(
                $this->messageArray($message),
                $presentation,
                ['provider' => $this->providerDescription()],
            ),
        ]);
    }

    /**
     * How the answer was produced.
     *
     * `is_language_model` is false for the assistant FynnEdge ships. Saying
     * so is the difference between an honest rules-based assistant and a
     * product implying a model it does not have.
     *
     * @return array<string, mixed>
     */
    private function providerDescription(): array
    {
        return [
            'available' => $this->provider->isAvailable(),
            'is_language_model' => $this->provider->isLanguageModel(),
            'name' => $this->provider->name(),
        ];
    }

    /** @return array<string, mixed> */
    private function conversationArray(Conversation $conversation): array
    {
        return [
            'id' => (string) $conversation->id,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
            'messages' => $conversation->relationLoaded('messages')
                ? $conversation->messages
                    ->map(fn (ConversationMessage $m) => $this->messageArray($m))
                    ->values()
                    ->all()
                : [],
        ];
    }

    /** @return array<string, mixed> */
    private function messageArray(ConversationMessage $message): array
    {
        return [
            'id' => (string) $message->id,
            'role' => $message->role,
            'text' => $message->content,
            'at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function owned(Request $request, string $id): Conversation
    {
        $conversation = $request->user()->conversations()->find($id);

        abort_if(
            $conversation === null,
            Response::HTTP_NOT_FOUND,
            'That conversation could not be found.',
        );

        return $conversation;
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'ai_unavailable' => response()->json([
                'message' => UnavailableFynnAiProvider::MESSAGE,
                'data' => [
                    'reason' => UnavailableFynnAiProvider::REASON,
                    'provider' => $this->providerDescription(),
                ],
            ], Response::HTTP_NOT_IMPLEMENTED),

            default => throw $e,
        };
    }
}
