<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\KycVerification;
use App\Services\Kyc\IdentityResolutionService;
use App\Services\Kyc\KycAlreadyRunning;
use App\Services\Kyc\KycConsentRequired;
use App\Services\Kyc\KycFieldNotFound;
use App\Services\Kyc\KycFieldNotResolvable;
use App\Services\Kyc\KycNotConfigured;
use App\Services\Kyc\KycService;
use App\Services\Kyc\KycUnsupportedDocument;
use App\Services\Kyc\KycVerificationState;
use App\Services\Vault\ExternalTransferRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identity verification on a document the customer has stored.
 *
 * Every route resolves the document through the authenticated user's own
 * relation, so another customer's id is simply not found. Nothing in a
 * request body decides whose document is checked, what the result is,
 * whether it is sample data, or who resolved a difference.
 */
class KycController extends Controller
{
    public function __construct(
        private readonly KycService $kyc,
        private readonly IdentityResolutionService $resolution,
    ) {}

    /**
     * GET /documents/{document}/kyc
     *
     * What FynnEdge already knows. Never asks a provider, so opening the
     * screen cannot cost the customer a charge.
     */
    public function show(Request $request, string $document): JsonResponse
    {
        return response()->json([
            'data' => $this->kyc
                ->statusFor($this->owned($request, $document))
                ->toArray(),
        ]);
    }

    /**
     * POST /documents/{document}/kyc
     *
     * Asks for the document to be checked, because the customer asked.
     */
    public function store(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            // "Check it again". Nothing else in the body is read: the
            // provider, the scenario, the sample flag and the outcome are
            // all server- and provider-owned.
            'force' => ['sometimes', 'boolean'],
        ]);

        $model = $this->owned($request, $document);

        try {
            $state = $this->kyc->verify(
                $model,
                (bool) ($validated['force'] ?? false),
            );
        } catch (KycNotConfigured) {
            return $this->refusal(
                KycVerificationState::NOT_CONFIGURED_MESSAGE,
                KycVerificationState::NOT_CONFIGURED,
                Response::HTTP_NOT_IMPLEMENTED,
            );
        } catch (KycUnsupportedDocument) {
            return $this->refusal(
                KycVerificationState::UNSUPPORTED_MESSAGE,
                KycVerificationState::UNSUPPORTED,
                Response::HTTP_NOT_IMPLEMENTED,
            );
        } catch (KycConsentRequired) {
            return $this->refusal(
                'Allow identity verification before FynnEdge can check this.',
                'consent_required',
                Response::HTTP_FORBIDDEN,
            );
        } catch (KycAlreadyRunning) {
            // Not an error. The check they asked for is already happening,
            // so they are told where it stands rather than charged again.
            return response()->json([
                'data' => $this->kyc->statusFor($model)->toArray(),
            ], Response::HTTP_ACCEPTED);
        } catch (ExternalTransferRefused $e) {
            /*
             * The file was not sent, because nothing had established it was
             * safe to send.
             *
             * A distinct answer from every other refusal here: the customer
             * needs to know their document is untouched and that this is
             * not a finding about their file unless it actually is.
             */
            return $this->refusal(
                $e->customerMessage(),
                $e->reason(),
                Response::HTTP_CONFLICT,
            );
        } catch (RuntimeException) {
            return $this->refusal(
                'That document could not be read from storage.',
                'document_missing',
                Response::HTTP_CONFLICT,
            );
        }

        return response()->json(
            ['data' => $state->toArray()],
            $state->status->isSettled()
                ? Response::HTTP_OK
                : Response::HTTP_ACCEPTED,
        );
    }

    /**
     * POST /documents/{document}/kyc/resolve
     *
     * What the customer decides about a difference: their profile is right,
     * or the document is and they want their profile corrected.
     *
     * Deliberately the only path by which a verification can change a
     * profile, and it runs only when a customer chooses one of these.
     */
    public function resolve(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'max:40'],
            'decision' => ['required', Rule::in(['keep_profile', 'update_profile'])],
        ]);

        $model = $this->owned($request, $document);
        $verification = $this->latestVerification($model);

        try {
            // The actor is the token's owner. A confirmed_by, actor_id or
            // user_id in the body reaches nothing.
            $user = $request->user();

            if ($validated['decision'] === 'update_profile') {
                $this->resolution->updateProfile($user, $verification, $validated['field']);
            } else {
                $this->resolution->keepProfile($user, $verification, $validated['field']);
            }
        } catch (KycFieldNotFound) {
            return $this->refusal(
                'That check has no such field.',
                'field_not_found',
                Response::HTTP_NOT_FOUND,
            );
        } catch (KycFieldNotResolvable) {
            return $this->refusal(
                'That field cannot be saved to your profile.',
                'field_not_resolvable',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'data' => $this->kyc->statusFor($model)->toArray(),
        ]);
    }

    private function refusal(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'reason' => $reason,
        ], $status);
    }

    /**
     * Ownership comes from the token. A document belonging to somebody else
     * is indistinguishable from one that does not exist.
     */
    private function owned(Request $request, string $id): Document
    {
        $document = $request->user()->documents()->find($id);

        abort_if(
            $document === null,
            Response::HTTP_NOT_FOUND,
            'That document could not be found.',
        );

        return $document;
    }

    private function latestVerification(Document $document): KycVerification
    {
        $verification = $this->kyc->latestFor($document);

        abort_if(
            $verification === null,
            Response::HTTP_NOT_FOUND,
            'That document has not been checked.',
        );

        return $verification;
    }
}
