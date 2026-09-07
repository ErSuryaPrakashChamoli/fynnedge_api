<?php

namespace App\Http\Controllers\Api;

use App\Domain\ProviderEvents\EventProcessingStatus;
use App\Domain\ProviderEvents\ProviderEventAuthenticationFailed;
use App\Domain\ProviderEvents\ProviderEventMalformed;
use App\Domain\ProviderEvents\ProviderEventRegistry;
use App\Http\Controllers\Controller;
use App\Services\ProviderEvents\SubmissionReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a provider tells FynnEdge something happened.
 *
 * NOT a customer endpoint. It carries no customer session, and a customer's
 * token authenticates nothing here — provider identity and customer identity
 * are different questions about different parties, and a route that accepted
 * either would let a customer report their own approval.
 *
 * Nothing about the delivery decides whose application it affects. The
 * provider's own reference resolves a submission, and the submission owns
 * the application. There is no path from anything in the body to a customer.
 *
 * The response is deliberately thin: an acknowledgement and nothing about
 * what FynnEdge holds. A caller that fails authentication learns only that.
 */
class ProviderEventController extends Controller
{
    /**
     * Bounded so a delivery cannot be used to push arbitrary volume at the
     * API. A canonical event is a few hundred bytes; 64 KB is generous for
     * a provider that sends more and far short of anything abusive.
     */
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly SubmissionReconciliationService $reconciliation,
    ) {}

    /**
     * POST /provider-events/{provider}
     */
    public function store(Request $request, string $provider): JsonResponse
    {
        /*
         * The provider code is matched against an allowlist and nothing
         * else. It never names a class, a driver or a service.
         *
         * An unknown code is a plain 404: whether FynnEdge integrates with
         * a given provider is not something an unauthenticated caller gets
         * to discover.
         */
        $source = ProviderEventRegistry::for($provider);

        if ($source === null) {
            return $this->refusal('Not found.', Response::HTTP_NOT_FOUND);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->refusal(
                'Payload too large.',
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE,
            );
        }

        try {
            $source['authenticator']->authenticate($request);
        } catch (ProviderEventAuthenticationFailed) {
            /*
             * The reason stays server-side.
             *
             * Telling a caller which check failed hands somebody probing
             * the boundary a map of it.
             */
            return $this->refusal('Unauthorized.', Response::HTTP_UNAUTHORIZED);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return $this->refusal(
                'Malformed event.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            $event = $source['normalizer']->normalize($payload);
        } catch (ProviderEventMalformed) {
            return $this->refusal(
                'Malformed event.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $record = $this->reconciliation->reconcile(
            $event,
            hash('sha256', $body),
        );

        /*
         * Acknowledged only when it is actually recorded.
         *
         * An event that could not be applied is not reported as processed:
         * a provider told it "OK" would stop redelivering something
         * FynnEdge never acted on.
         */
        $status = $record->processingStatus() === EventProcessingStatus::Failed
            ? Response::HTTP_INTERNAL_SERVER_ERROR
            : Response::HTTP_ACCEPTED;

        return response()->json([
            'data' => [
                // Enough for a provider to correlate a delivery, and
                // nothing about the customer or their application.
                'received' => true,
                'result' => $record->processing_status,
            ],
        ], $status);
    }

    private function refusal(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
