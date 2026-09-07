<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ai\MemoryPolicy;
use App\Http\Controllers\Controller;
use App\Models\AiMemory;
use App\Services\FynnAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What FynnAI remembers, and the customer's control over it.
 */
class AiMemoryController extends Controller
{
    public function __construct(private readonly FynnAiService $ai) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = $this->ai->mayRemember($user);

        return response()->json([
            'data' => [
                // Off without consent, and the list is empty because
                // withdrawal deletes what was held.
                'memory_enabled' => $allowed,
                'memories' => $this->ai->memories($user)
                    ->map(fn (AiMemory $m) => [
                        'id' => (string) $m->id,
                        'key' => $m->key,
                        'label' => MemoryPolicy::allowed()[$m->key] ?? $m->key,
                        'value' => $m->value,
                        'source' => $m->source,
                        'updated_at' => $m->updated_at?->toIso8601String(),
                    ])->values()->all(),

                // What FynnEdge is willing to remember at all.
                'policy' => array_map(
                    fn (string $key, string $purpose) => [
                        'key' => $key,
                        'purpose' => $purpose,
                    ],
                    MemoryPolicy::keys(),
                    array_values(MemoryPolicy::allowed()),
                ),
            ],
        ]);
    }

    public function destroy(Request $request, string $memory): JsonResponse
    {
        $model = $request->user()->aiMemories()->find($memory);

        abort_if(
            $model === null,
            Response::HTTP_NOT_FOUND,
            'That memory could not be found.',
        );

        $this->ai->forget($request->user(), $model);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function clear(Request $request): JsonResponse
    {
        $this->ai->forgetAll($request->user());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
