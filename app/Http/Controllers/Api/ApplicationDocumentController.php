<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoanApplication;
use App\Services\ApplicationDocuments\ApplicationDocumentService;
use App\Services\ApplicationDocuments\ApplicationNotEditable;
use App\Services\ApplicationDocuments\DocumentNotUsable;
use App\Services\ApplicationDocuments\UnknownRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The documents an application needs, and which of the customer's own they
 * have chosen for it.
 *
 * Every route resolves the application through the authenticated user's own
 * relation, and every document through theirs, so another customer's id is
 * simply not found on either side. Nothing in a request body decides whose
 * application or whose document is involved.
 *
 * Nothing here sends anything anywhere.
 */
class ApplicationDocumentController extends Controller
{
    public function __construct(
        private readonly ApplicationDocumentService $documents,
    ) {}

    /**
     * GET /applications/{application}/documents
     *
     * The checklist, recomputed from what is true now — so a document
     * deleted from the vault shows as missing the moment anybody looks.
     */
    public function index(Request $request, string $application): JsonResponse
    {
        return response()->json([
            'data' => $this->documents
                ->checklistFor($this->owned($request, $application))
                ->toArray(),
        ]);
    }

    /**
     * POST /applications/{application}/documents
     *
     * Chooses one of the customer's vault documents for a requirement.
     * Choosing is not confirming: the slot becomes "needs your
     * confirmation", and they still have to say it is the right one.
     */
    public function store(Request $request, string $application): JsonResponse
    {
        $validated = $request->validate([
            'requirement_code' => ['required', 'string', 'max:40'],
            'document_id' => ['required', 'string', 'max:40'],
        ]);

        $model = $this->owned($request, $application);

        try {
            $checklist = $this->documents->attach(
                // The owner is the token holder. A user_id in the body
                // reaches nothing.
                user: $request->user(),
                application: $model,
                requirementCode: $validated['requirement_code'],
                documentId: $validated['document_id'],
            );
        } catch (ApplicationNotEditable) {
            return $this->refusal(
                'This application can no longer be changed.',
                'application_not_editable',
                Response::HTTP_CONFLICT,
            );
        } catch (UnknownRequirement) {
            return $this->refusal(
                'This application does not ask for that document.',
                'document_requirement_missing',
                Response::HTTP_NOT_FOUND,
            );
        } catch (DocumentNotUsable $e) {
            return $this->documentRefusal($e);
        }

        return response()->json(['data' => $checklist->toArray()]);
    }

    /**
     * DELETE /applications/{application}/documents/{requirement}
     *
     * Removes the choice. The document itself stays in the vault, untouched.
     */
    public function destroy(
        Request $request,
        string $application,
        string $requirement,
    ): JsonResponse {
        $model = $this->owned($request, $application);

        try {
            $checklist = $this->documents->detach($model, $requirement);
        } catch (ApplicationNotEditable) {
            return $this->refusal(
                'This application can no longer be changed.',
                'application_not_editable',
                Response::HTTP_CONFLICT,
            );
        } catch (UnknownRequirement) {
            return $this->refusal(
                'This application does not ask for that document.',
                'document_requirement_missing',
                Response::HTTP_NOT_FOUND,
            );
        }

        return response()->json(['data' => $checklist->toArray()]);
    }

    /**
     * POST /applications/{application}/documents/{requirement}/review
     *
     * The customer's decision: this is the document to use, or it is not.
     *
     * Accepting is the customer confirming their own choice. It is not
     * verification by FynnEdge, not approval, and nobody outside FynnEdge
     * has seen the document.
     */
    public function review(
        Request $request,
        string $application,
        string $requirement,
    ): JsonResponse {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
        ]);

        $model = $this->owned($request, $application);

        try {
            $checklist = $this->documents->review(
                $model,
                $requirement,
                $validated['decision'] === 'accepted',
            );
        } catch (ApplicationNotEditable) {
            return $this->refusal(
                'This application can no longer be changed.',
                'application_not_editable',
                Response::HTTP_CONFLICT,
            );
        } catch (UnknownRequirement) {
            return $this->refusal(
                'This application does not ask for that document.',
                'document_requirement_missing',
                Response::HTTP_NOT_FOUND,
            );
        } catch (DocumentNotUsable $e) {
            return $this->documentRefusal($e);
        }

        return response()->json(['data' => $checklist->toArray()]);
    }

    private function documentRefusal(DocumentNotUsable $e): JsonResponse
    {
        return match ($e->reason) {
            'document_not_found' => $this->refusal(
                'That document could not be found.',
                'document_not_found',
                Response::HTTP_NOT_FOUND,
            ),
            'wrong_document_type' => $this->refusal(
                'That document is not the kind this requirement asks for.',
                'wrong_document_type',
                Response::HTTP_CONFLICT,
            ),
            'no_document_selected' => $this->refusal(
                'Choose a document before confirming it.',
                'no_document_selected',
                Response::HTTP_CONFLICT,
            ),
            default => $this->refusal(
                'That document is no longer available in FynnVault.',
                'document_not_usable',
                Response::HTTP_CONFLICT,
            ),
        };
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
