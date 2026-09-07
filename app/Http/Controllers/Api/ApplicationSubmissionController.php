<?php

namespace App\Http\Controllers\Api;

use App\Domain\Lender\PayloadNotPermitted;
use App\Domain\Lender\ProviderMappingMissing;
use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Services\Lender\ApplicationNotSubmittable;
use App\Services\Lender\LenderApplicationSubmissionService;
use App\Services\Lender\SubmissionAlreadyMade;
use App\Services\Lender\SubmissionConsentRequired;
use App\Services\Lender\SubmissionNotConfigured;
use App\Services\Lender\SubmissionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending an application to an external provider.
 *
 * Both routes resolve the application through the authenticated user's own
 * relation, so another customer's id is simply not found. Nothing in a
 * request body decides whose application is sent, which provider receives
 * it, whether the result is simulated, or what the outcome was.
 */
class ApplicationSubmissionController extends Controller
{
    public function __construct(
        private readonly LenderApplicationSubmissionService $submissions,
    ) {}

    /**
     * GET /applications/{application}/submission
     *
     * Where the external submission stands. Never calls a provider, so
     * opening the screen cannot send anything.
     */
    public function show(Request $request, string $application): JsonResponse
    {
        return response()->json([
            'data' => $this->submissions
                ->stateFor($this->owned($request, $application))
                ->toArray(),
        ]);
    }

    /**
     * POST /applications/{application}/submission
     *
     * Sends it, because the customer asked with the destination in front of
     * them. There are no body parameters at all: the provider, the outcome
     * and the simulated flag are server- and provider-owned, and an
     * application id in the body would reach nothing.
     */
    public function store(Request $request, string $application): JsonResponse
    {
        $model = $this->owned($request, $application);

        try {
            $state = $this->submissions->submit($model);
        } catch (SubmissionNotConfigured) {
            return $this->refusal(
                SubmissionState::NOT_CONFIGURED_MESSAGE,
                SubmissionState::NOT_CONFIGURED,
                Response::HTTP_NOT_IMPLEMENTED,
            );
        } catch (SubmissionConsentRequired) {
            return $this->refusal(
                'Allow FynnEdge to send this application before submitting it.',
                'submission_consent_required',
                Response::HTTP_FORBIDDEN,
            );
        } catch (ApplicationNotSubmittable) {
            return $this->refusal(
                'That application cannot be sent.',
                'application_not_submittable',
                Response::HTTP_CONFLICT,
            );
        } catch (SubmissionAlreadyMade) {
            /*
             * Not an error, and specifically not a second submission.
             *
             * The customer is shown where the existing attempt stands. This
             * is what makes a double tap safe: the second request answers
             * from the first attempt rather than creating another.
             */
            return response()->json([
                'data' => $this->submissions->stateFor($model)->toArray(),
            ], Response::HTTP_OK);
        } catch (PayloadNotPermitted) {
            /*
             * A mapper offered a field FynnEdge does not send.
             *
             * Nothing left: the payload is built before the lock and before
             * any attempt row, so this application is exactly as it was.
             * The customer is told the same thing as any other operational
             * gap, because which field a mapper got wrong is FynnEdge's
             * problem and not something they can act on.
             */
            return $this->refusal(
                'This application cannot be sent to the provider right now. '
                    .'Your application is safe in FynnEdge.',
                'payload_not_permitted',
                Response::HTTP_CONFLICT,
            );
        } catch (ProviderMappingMissing) {
            // An operational gap, not a failure of the customer's
            // application. Submitting under a guessed product code would
            // apply for something nobody chose.
            return $this->refusal(
                'This product cannot be sent to the provider right now. '
                    .'Your application is safe in FynnEdge.',
                'provider_mapping_missing',
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(['data' => $state->toArray()]);
    }

    private function refusal(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], $status);
    }

    /**
     * Ownership comes from the token. Another customer's application is
     * indistinguishable from one that does not exist.
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
}
