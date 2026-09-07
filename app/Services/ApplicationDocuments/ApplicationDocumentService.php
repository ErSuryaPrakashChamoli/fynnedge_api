<?php

namespace App\Services\ApplicationDocuments;

use App\Domain\Application\ApplicationStatus;
use App\Domain\ApplicationDocuments\DocumentRequirement;
use App\Domain\ApplicationDocuments\RequirementCatalogue;
use App\Domain\ApplicationDocuments\RequirementStatus;
use App\Domain\Vault\ContentDigest;
use App\Models\ApplicationDocument;
use App\Models\ApplicationEvent;
use App\Models\Document;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Which documents an application needs, which the customer has chosen, and
 * whether it is ready.
 *
 * Four rules shape this class.
 *
 * Readiness is calculated here and nowhere else. A controller that decided
 * for itself, or a screen that counted ticks, would eventually disagree with
 * this — and the customer would be shown two answers to one question.
 *
 * Nothing is copied. A requirement holds a reference to a vault document.
 * The file stays where the customer put it, under the vault's own ownership
 * and deletion rules, and this layer never learns a storage key.
 *
 * A confirmation describes a file. If the bytes behind a confirmed document
 * change, the confirmation described something else and the slot goes back
 * to needing review rather than quietly carrying over.
 *
 * And readiness is not submission. Every required document being ready
 * means the customer has what an application of this kind ordinarily needs.
 * It does not send anything, does not call a provider, and does not mean a
 * lender has anything.
 */
class ApplicationDocumentService
{
    public function __construct(private readonly ContentDigest $digests) {}

    /**
     * The checklist for an application.
     *
     * Recomputed on every read rather than stored, so a document deleted
     * from the vault is reflected the moment anybody looks — there is no
     * cached readiness to go stale.
     */
    public function checklistFor(LoanApplication $application): DocumentChecklist
    {
        $requirements = RequirementCatalogue::forApplication($application);
        $chosen = $application->documents()->with('document')->get()
            ->keyBy('requirement_code');

        $items = [];

        foreach ($requirements as $requirement) {
            $items[] = $this->itemFor(
                $requirement,
                $chosen->get($requirement->code),
            );
        }

        return new DocumentChecklist($application, $items);
    }

    /**
     * One requirement, with whatever the customer put in it.
     *
     * The state is derived here from what is actually true right now: is
     * there a choice, is the document still there, and has the customer
     * confirmed the file as it currently stands.
     */
    private function itemFor(
        DocumentRequirement $requirement,
        ?ApplicationDocument $chosen,
    ): ChecklistItem {
        if ($chosen === null) {
            return new ChecklistItem($requirement, RequirementStatus::Missing);
        }

        // The customer said this one will not do. Their judgement stands
        // until they choose differently.
        if ($chosen->status() === RequirementStatus::NotSuitable) {
            return new ChecklistItem(
                $requirement,
                RequirementStatus::NotSuitable,
                $chosen->document,
                $chosen,
            );
        }

        if (! $chosen->hasUsableDocument()) {
            /*
             * The document is gone from the vault, or its file is.
             *
             * Reported as missing rather than left as it was: a slot
             * pointing at a deleted document must never read as ready, and
             * the customer needs to be told to choose another.
             */
            return new ChecklistItem(
                $requirement,
                RequirementStatus::Missing,
                null,
                $chosen,
                unavailable: true,
            );
        }

        if ($chosen->status() === RequirementStatus::Ready) {
            // A confirmation describes a file. If the bytes changed, it
            // described a different one.
            $stale = $chosen->file_digest !== null
                && $chosen->file_digest !== $this->digestOf($chosen->document);

            return new ChecklistItem(
                $requirement,
                $stale ? RequirementStatus::NeedsReview : RequirementStatus::Ready,
                $chosen->document,
                $chosen,
                stale: $stale,
            );
        }

        return new ChecklistItem(
            $requirement,
            RequirementStatus::Available,
            $chosen->document,
            $chosen,
        );
    }

    /**
     * Attaches a vault document to a requirement.
     *
     * Attaching is not confirming. The slot becomes Available, and the
     * customer still has to say this is the document to use.
     *
     * @throws ApplicationNotEditable|UnknownRequirement|DocumentNotUsable
     */
    public function attach(
        User $user,
        LoanApplication $application,
        string $requirementCode,
        string $documentId,
    ): DocumentChecklist {
        $this->assertEditable($application);

        $requirement = RequirementCatalogue::find($application, $requirementCode);

        if ($requirement === null) {
            throw new UnknownRequirement;
        }

        // Ownership resolved through the customer's own relation, so
        // another customer's document is not found rather than refused.
        $document = $user->documents()->find($documentId);

        if ($document === null) {
            throw new DocumentNotUsable('document_not_found');
        }

        if ($document->type() !== $requirement->documentType) {
            // A bank statement in the identity slot is a mistake worth
            // catching now rather than at submission.
            throw new DocumentNotUsable('wrong_document_type');
        }

        if (! $document->exists()) {
            throw new DocumentNotUsable('document_not_available');
        }

        DB::transaction(function () use ($user, $application, $requirement, $document) {
            /*
             * One active choice per slot.
             *
             * updateOrCreate against the unique key, so two requests racing
             * to attach different documents leave one current choice rather
             * than an ambiguous pair.
             */
            ApplicationDocument::updateOrCreate(
                [
                    'loan_application_id' => $application->getKey(),
                    'requirement_code' => $requirement->code,
                ],
                [
                    'user_id' => $user->getKey(),
                    'document_id' => $document->getKey(),

                    // Attached, not confirmed. The customer still has to
                    // look at it and say so.
                    'status' => RequirementStatus::Available->value,
                    'file_digest' => null,
                    'reviewed_at' => null,
                ],
            );
        });

        $application->recordEvent(
            'Document added',
            sprintf(
                '%s: %s selected from FynnVault.',
                $requirement->title,
                $document->display_name,
            ),

            // FynnEdge's own act, and labelled as such: no provider was
            // involved in any part of this.
            ApplicationEvent::SOURCE_FYNNEDGE,
        );

        return $this->checklistFor($application->fresh());
    }

    /**
     * Removes the customer's choice for a requirement.
     *
     * @throws ApplicationNotEditable|UnknownRequirement
     */
    public function detach(
        LoanApplication $application,
        string $requirementCode,
    ): DocumentChecklist {
        $this->assertEditable($application);

        $requirement = RequirementCatalogue::find($application, $requirementCode);

        if ($requirement === null) {
            throw new UnknownRequirement;
        }

        $application->documents()
            ->where('requirement_code', $requirementCode)
            ->delete();

        $application->recordEvent(
            'Document removed',
            $requirement->title.': the selected document was removed.',
            ApplicationEvent::SOURCE_FYNNEDGE,
        );

        return $this->checklistFor($application->fresh());
    }

    /**
     * The customer's decision about the document they chose.
     *
     * Accepting means "this is the document to use for my application". It
     * is not verification, not approval, and nobody outside FynnEdge has
     * seen it.
     *
     * @throws ApplicationNotEditable|UnknownRequirement|DocumentNotUsable
     */
    public function review(
        LoanApplication $application,
        string $requirementCode,
        bool $accepted,
    ): DocumentChecklist {
        $this->assertEditable($application);

        $requirement = RequirementCatalogue::find($application, $requirementCode);

        if ($requirement === null) {
            throw new UnknownRequirement;
        }

        $chosen = $application->documents()
            ->where('requirement_code', $requirementCode)
            ->first();

        if ($chosen === null) {
            throw new DocumentNotUsable('no_document_selected');
        }

        if ($accepted && ! $chosen->hasUsableDocument()) {
            // Nothing to confirm. The document behind this choice is gone.
            throw new DocumentNotUsable('document_not_available');
        }

        $chosen->update([
            'status' => $accepted
                ? RequirementStatus::Ready->value
                : RequirementStatus::NotSuitable->value,

            // The file they confirmed, so a later change is detectable.
            'file_digest' => $accepted
                ? $this->digestOf($chosen->document)
                : null,

            'reviewed_at' => now(),
        ]);

        $application->recordEvent(
            $accepted ? 'Document confirmed' : 'Document marked unsuitable',
            $accepted
                ? $requirement->title.': confirmed by you for this '
                    .'application. Nothing has been sent anywhere.'
                : $requirement->title.': you marked this document as not '
                    .'suitable.',
            ApplicationEvent::SOURCE_FYNNEDGE,
        );

        return $this->checklistFor($application->fresh());
    }

    /**
     * Which application states allow document preparation.
     *
     * Module 9's state machine is the authority and is not modified. A
     * cancelled application, or one a provider has ruled on, is not a place
     * to be choosing documents.
     *
     * @throws ApplicationNotEditable
     */
    private function assertEditable(LoanApplication $application): void
    {
        $allowed = in_array($application->status(), [
            ApplicationStatus::Started,
            ApplicationStatus::Submitted,
        ], true);

        if (! $allowed) {
            throw new ApplicationNotEditable;
        }
    }

    /**
     * The bytes of a stored document.
     *
     * Read and hashed, never kept. This is the only place in the module
     * that touches a file at all, and what survives is 64 characters that
     * say whether it changed.
     */
    private function digestOf(?Document $document): ?string
    {
        return $this->digests->of($document);
    }
}

/** The application is not in a state where documents can be changed. */
class ApplicationNotEditable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That application cannot be changed.');
    }
}

/** No such requirement exists on this application. */
class UnknownRequirement extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That application does not ask for that document.');
    }
}

/** The chosen document cannot fill this requirement. */
class DocumentNotUsable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('That document cannot be used here.');
    }
}
