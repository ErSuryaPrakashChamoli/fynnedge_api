<?php

namespace App\Http\Controllers\Api;

use App\Domain\Support\CaseNotOpen;
use App\Domain\Support\ContextUnavailable;
use App\Domain\Support\InvalidCaseTransition;
use App\Domain\Support\SupportCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupportCaseRequest;
use App\Http\Requests\StoreSupportMessageRequest;
use App\Http\Resources\SupportCaseResource;
use App\Models\SupportCase;
use App\Services\Support\ContextResolver;
use App\Services\Support\SupportCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asking FynnEdge for help, and following what happened.
 *
 * NO ADVISOR SYSTEM, SUPPORT CRM OR CHAT PROVIDER IS CONNECTED. Everything
 * here writes to FynnEdge's own store; nothing is forwarded anywhere and
 * nothing here can make it look as though somebody has replied.
 *
 * Every route resolves the case through `$request->user()->supportCases()`,
 * so another customer's id is not found rather than refused — the same 404,
 * with the same body, as an id that never existed.
 *
 * What no endpoint accepts: a status, a priority, a reference, an author or
 * a user. All of those are FynnEdge's, and a request body that could set
 * any of them is a request body that could resolve somebody's case or put
 * words in FynnEdge's mouth.
 */
class SupportController extends Controller
{
    public function __construct(
        private readonly SupportCaseService $cases,
        private readonly ContextResolver $contexts,
    ) {}

    /**
     * GET /support/categories
     *
     * What a customer can ask about, in the order they are offered. Labels
     * come from the enum so the app never spells one differently.
     */
    public function categories(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (SupportCategory $c) => [
                'id' => $c->value,
                'label' => $c->label(),
                'description' => $c->description(),
            ], SupportCategory::inOfferedOrder()),
        ]);
    }

    /** GET /support/cases — newest first. */
    public function index(Request $request): JsonResponse
    {
        $cases = $request->user()->supportCases()
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => SupportCaseResource::collection($cases)->resolve(),
            'meta' => [
                'open_count' => $request->user()->supportCases()
                    ->whereNotIn('status', ['closed'])
                    ->count(),
            ],
        ]);
    }

    /**
     * POST /support/cases
     *
     * Idempotent on the Idempotency-Key header, the same as an application:
     * a customer tapping "Get help" twice has asked once.
     */
    public function store(StoreSupportCaseRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            // Checked before anything is written. A context naming another
            // customer's application fails here, and fails the same way as
            // one naming an application that does not exist.
            $context = $this->contexts->resolve(
                $request->user(),
                $validated['context_type'] ?? null,
                isset($validated['context_id']) ? (string) $validated['context_id'] : null,
            );
        } catch (ContextUnavailable $e) {
            return $this->refusal($e->getMessage(), 'context_unavailable', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $case = $this->cases->open(
            user: $request->user(),
            category: SupportCategory::from($validated['category']),
            subject: $validated['subject'],
            description: $validated['description'],
            context: $context,
            idempotencyKey: $this->idempotencyKey($request),
        );

        // A repeat of the same request is not a second request, so it
        // answers 200 with the case that already exists.
        return response()
            ->json(['data' => SupportCaseResource::detailed($case->load(['messages', 'events']))->resolve()])
            ->setStatusCode($case->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Request $request, string $case): JsonResponse
    {
        return response()->json([
            'data' => SupportCaseResource::detailed(
                $this->owned($request, $case)->load(['messages', 'events'])
            )->resolve(),
        ]);
    }

    /**
     * POST /support/cases/{case}/messages
     *
     * The customer adds something. Authored as the customer by the server,
     * always — there is no field for who wrote it.
     */
    public function addMessage(
        StoreSupportMessageRequest $request,
        string $case,
    ): JsonResponse {
        $model = $this->owned($request, $case);

        try {
            $this->cases->addCustomerMessage($model, $request->user(), $request->validated()['body']);
        } catch (CaseNotOpen $e) {
            return $this->refusal($e->getMessage(), 'case_closed', Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => SupportCaseResource::detailed(
                $model->fresh()->load(['messages', 'events'])
            )->resolve(),
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /support/cases/{case}/close
     *
     * The customer no longer needs help. Recorded with a reason, so a
     * request they closed is distinguishable from one FynnEdge resolved.
     */
    public function close(Request $request, string $case): JsonResponse
    {
        $model = $this->owned($request, $case);

        try {
            $closed = $this->cases->closeByCustomer($model);
        } catch (InvalidCaseTransition $e) {
            // Already closed. Refused rather than silently accepted, so a
            // client is told the truth about what it asked for.
            return $this->refusal(
                'That request is already closed.',
                'case_closed',
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json([
            'data' => SupportCaseResource::detailed($closed->load(['messages', 'events']))->resolve(),
        ]);
    }

    /**
     * Resolved through the customer's own relation.
     *
     * Not route-model binding: that finds the row first and checks
     * ownership afterwards, which makes "not yours" distinguishable from
     * "no such thing".
     */
    private function owned(Request $request, string $id): SupportCase
    {
        $case = $request->user()->supportCases()->find($id);

        abort_if($case === null, Response::HTTP_NOT_FOUND, 'Request not found.');

        return $case;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? substr($key, 0, 64) : null;
    }

    private function refusal(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'reason' => $reason], $status);
    }
}
