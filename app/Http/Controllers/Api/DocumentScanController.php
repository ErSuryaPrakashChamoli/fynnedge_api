<?php

namespace App\Http\Controllers\Api;

use App\Domain\Scan\ReviewDecision;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentScan;
use App\Services\Scan\DocumentScanState;
use App\Services\Scan\DocumentUnderstandingService;
use App\Services\Scan\ExtractionReviewService;
use App\Services\Scan\ScanAlreadyRunning;
use App\Services\Scan\ScanConsentRequired;
use App\Services\Scan\ScanFieldNotApplicable;
use App\Services\Scan\ScanFieldNotFound;
use App\Services\Scan\ScanNotConfigured;
use App\Services\Scan\ScanUnsupportedDocument;
use App\Services\Vault\ExternalTransferRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * FynnScan — reading a document the customer has stored.
 *
 * Every route resolves the document through the authenticated user's own
 * relation, so another customer's id is simply not found. Nothing in a
 * request body decides whose document is read, whose profile is changed, or
 * who confirmed anything.
 */
class DocumentScanController extends Controller
{
    public function __construct(
        private readonly DocumentUnderstandingService $understanding,
        private readonly ExtractionReviewService $reviews,
    ) {}

    /**
     * GET /documents/{document}/scan
     *
     * What FynnEdge already knows. Never asks a provider, so opening the
     * screen cannot cost the customer a charge.
     */
    public function show(Request $request, string $document): JsonResponse
    {
        return response()->json([
            'data' => $this->understanding
                ->statusFor($this->owned($request, $document))
                ->toArray(),
        ]);
    }

    /**
     * POST /documents/{document}/scan
     *
     * Asks for the document to be read, because the customer asked.
     */
    public function store(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            // "Scan again" after a rejection or a failure. Nothing else in
            // the body is read: the provider, the scenario and the sample
            // flag are all server-owned.
            'force' => ['sometimes', 'boolean'],
        ]);

        $model = $this->owned($request, $document);

        try {
            $state = $this->understanding->scan(
                $model,
                (bool) ($validated['force'] ?? false),
            );
        } catch (ScanNotConfigured) {
            return $this->refusal(
                DocumentScanState::NOT_CONFIGURED_MESSAGE,
                DocumentScanState::NOT_CONFIGURED,
                Response::HTTP_NOT_IMPLEMENTED,
            );
        } catch (ScanUnsupportedDocument) {
            return $this->refusal(
                DocumentScanState::UNSUPPORTED_MESSAGE,
                DocumentScanState::UNSUPPORTED,
                Response::HTTP_NOT_IMPLEMENTED,
            );
        } catch (ScanConsentRequired) {
            return $this->refusal(
                'Allow document processing before FynnEdge can read this.',
                'consent_required',
                Response::HTTP_FORBIDDEN,
            );
        } catch (ScanAlreadyRunning) {
            // Not an error. The reading they asked for is already happening,
            // so they are told where it stands rather than charged again.
            return response()->json([
                'data' => $this->understanding
                    ->statusFor($model)
                    ->toArray(),
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
            // A document whose bytes are gone. Nothing is invented about
            // what it contained.
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
     * POST /documents/{document}/scan/review
     *
     * The customer's answer about one field: it is right, it is wrong and
     * here is the right figure, or it is wrong and they are not supplying
     * one. Optionally, and only when they ask, the confirmed figure is
     * carried into their financial profile.
     */
    public function review(Request $request, string $document): JsonResponse
    {
        $validated = $request->validate([
            'field' => ['required', 'string', 'max:40'],
            'decision' => [
                'required',
                Rule::in(array_map(
                    fn (ReviewDecision $d) => $d->value,
                    ReviewDecision::cases(),
                )),
            ],
            'value' => ['sometimes', 'nullable', 'string', 'max:200'],
            'apply_to_profile' => ['sometimes', 'boolean'],
        ]);

        $model = $this->owned($request, $document);
        $scan = $this->latestScan($model);

        try {
            $this->reviews->review(
                // The actor is the token's owner. A confirmed_by, actor_id
                // or user_id in the body reaches nothing.
                user: $request->user(),
                scan: $scan,
                fieldKey: $validated['field'],
                decision: ReviewDecision::from($validated['decision']),
                customerValue: $validated['value'] ?? null,
                applyToProfile: (bool) ($validated['apply_to_profile'] ?? false),
            );
        } catch (ScanFieldNotFound) {
            return $this->refusal(
                'That scan has no such field.',
                'field_not_found',
                Response::HTTP_NOT_FOUND,
            );
        } catch (ScanFieldNotApplicable) {
            return $this->refusal(
                'That value cannot be saved to your profile.',
                'field_not_applicable',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'data' => $this->understanding->statusFor($model)->toArray(),
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

    private function latestScan(Document $document): DocumentScan
    {
        $scan = $this->understanding->latestFor($document);

        abort_if(
            $scan === null,
            Response::HTTP_NOT_FOUND,
            'That document has not been read.',
        );

        return $scan;
    }
}
