<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLoanApplicationRequest;
use App\Http\Requests\SubmitLoanApplicationRequest;
use App\Http\Requests\UpdateLoanApplicationRequest;
use App\Http\Resources\LoanApplicationResource;
use App\Models\LoanApplication;
use App\Services\LoanApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's applications.
 *
 * Every query is scoped through the authenticated user's relation, so an id
 * belonging to someone else is simply not found — the same 404, with the same
 * message, as an id that never existed.
 */
class ApplicationController extends Controller
{
    public function __construct(
        private readonly LoanApplicationService $applications,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Newest first, ties broken by id: the same order on every request.
        $applications = $request->user()->applications()
            ->with('events')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return LoanApplicationResource::collection($applications);
    }

    public function store(StoreLoanApplicationRequest $request): JsonResponse
    {
        try {
            $application = $this->applications->create(
                $request->user(),
                $request->validated(),
                $this->idempotencyKey($request),
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        // A repeat of the same operation is not a second creation, so it
        // answers 200 with the application that already exists.
        $created = $application->wasRecentlyCreated;

        return (new LoanApplicationResource($application->load('events')))
            ->response()
            ->setStatusCode($created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function show(Request $request, string $application): LoanApplicationResource
    {
        return new LoanApplicationResource(
            $this->owned($request, $application)->load('events')
        );
    }

    public function update(
        UpdateLoanApplicationRequest $request,
        string $application,
    ): JsonResponse|LoanApplicationResource {
        try {
            $updated = $this->applications->update(
                $this->owned($request, $application),
                $request->validated(),
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return new LoanApplicationResource($updated->load('events'));
    }

    public function submit(
        SubmitLoanApplicationRequest $request,
        string $application,
    ): JsonResponse|LoanApplicationResource {
        try {
            $submitted = $this->applications->submit(
                $this->owned($request, $application)
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return new LoanApplicationResource($submitted->load('events'));
    }

    public function cancel(
        Request $request,
        string $application,
    ): JsonResponse|LoanApplicationResource {
        try {
            $cancelled = $this->applications->cancel(
                $this->owned($request, $application)
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return new LoanApplicationResource($cancelled->load('events'));
    }

    /**
     * Resolved through the customer's own relation.
     *
     * Not route-model binding: that would find the row first and check
     * ownership second, which answers differently for "not yours" than for
     * "does not exist" and tells the caller which one it was.
     */
    private function owned(Request $request, string $id): LoanApplication
    {
        $application = $request->user()->applications()->find($id);

        abort_if(
            $application === null,
            Response::HTTP_NOT_FOUND,
            'That application could not be found.',
        );

        return $application;
    }

    /** The client's key for "this is the same operation as before". */
    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? substr($key, 0, 64) : null;
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'product_unavailable' => response()->json([
                'message' => 'That product is no longer available.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            'not_editable' => response()->json([
                'message' => 'This application has been submitted and can no longer be changed.',
            ], Response::HTTP_CONFLICT),

            'not_submittable' => response()->json([
                'message' => 'This application cannot be submitted.',
            ], Response::HTTP_CONFLICT),

            'not_cancellable' => response()->json([
                'message' => 'This application can no longer be cancelled.',
            ], Response::HTTP_CONFLICT),

            'submission_failed' => response()->json([
                'message' => 'The submission was not accepted. Nothing has been sent.',
            ], Response::HTTP_BAD_GATEWAY),

            default => throw $e,
        };
    }
}
