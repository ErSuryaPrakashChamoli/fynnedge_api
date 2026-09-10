<?php

namespace App\Services\Support;

use App\Domain\Application\CustomerStatus;
use App\Domain\Lender\SubmissionOutcome;
use App\Domain\Notifications\CustomerText;
use App\Domain\Support\ContextUnavailable;
use App\Domain\Support\ResolvedContext;
use App\Domain\Support\SupportContextType;
use App\Models\User;

/**
 * Turning "this is about application 42" into something FynnEdge has
 * checked.
 *
 * The whole security surface of support context is here. `context_type` and
 * `context_id` arrive in a request body, which means they arrive from
 * whoever is holding the phone, and a customer typing another customer's
 * application id must get the same answer as one typing an id that never
 * existed.
 *
 * So every lookup goes through the authenticated customer's own relation —
 * `$user->applications()->find($id)`, never `LoanApplication::find($id)`
 * followed by an ownership check. The second form finds the row first, and
 * the difference between "not yours" and "no such thing" becomes visible.
 *
 * What comes back holds a reference, a status sentence and a product name.
 * Nothing else from the record travels: a support case is a request for
 * help, not a second copy of the customer's file.
 */
class ContextResolver
{
    /**
     * @throws ContextUnavailable
     */
    public function resolve(User $user, ?string $type, ?string $id): ?ResolvedContext
    {
        if ($type === null || $type === '') {
            // No context is a perfectly ordinary request. "Something else"
            // is a real thing to ask about.
            return null;
        }

        $context = SupportContextType::tryFrom($type);

        if ($context === null) {
            // A type FynnEdge does not have. Refused rather than stored as
            // a string, which is what makes context_type an allowlist
            // instead of a place to put a class name.
            throw new ContextUnavailable;
        }

        if (! $context->needsId()) {
            // Where the customer was, with nothing to look up. An id sent
            // alongside is ignored rather than trusted.
            return ResolvedContext::bare($context);
        }

        if ($id === null || $id === '' || ! ctype_digit($id)) {
            throw new ContextUnavailable;
        }

        return match ($context) {
            SupportContextType::Application => $this->application($user, (int) $id),
            SupportContextType::Document => $this->document($user, (int) $id),
            SupportContextType::Goal => $this->goal($user, (int) $id),
            default => throw new ContextUnavailable,
        };
    }

    /**
     * An application, described the way Module 28 describes it.
     *
     * The status sentence is CustomerStatus's, not one written here. A
     * support screen inventing its own wording for "we could not confirm
     * this was received" is how two parts of the product end up telling a
     * customer two different things about the same application.
     */
    private function application(User $user, int $id): ResolvedContext
    {
        $application = $user->applications()->find($id);

        if ($application === null) {
            throw new ContextUnavailable;
        }

        $outcome = $application->latestSubmission()?->outcome() ?? SubmissionOutcome::NotAttempted;
        $status = CustomerStatus::of($application->status(), $outcome);

        return new ResolvedContext(
            type: SupportContextType::Application,
            id: $application->getKey(),
            reference: $application->reference,
            statusLabel: $status->label,
            productName: $application->product_name,

            /*
             * The one blocked state in the product.
             *
             * FynnEdge sent this and never heard back. The customer has been
             * told not to submit it again, so they cannot move and cannot
             * safely retry — which is what makes their request different
             * from a question.
             */
            isBlocked: $outcome === SubmissionOutcome::Unknown,
        );
    }

    /**
     * A document, by kind and nothing else.
     *
     * Not its display name, not its filename, not its size, and above all
     * not its contents. What a support request needs is which document is
     * being asked about; what it must never carry is anything out of it.
     */
    private function document(User $user, int $id): ResolvedContext
    {
        $document = $user->documents()->find($id);

        if ($document === null) {
            throw new ContextUnavailable;
        }

        return new ResolvedContext(
            type: SupportContextType::Document,
            id: $document->getKey(),
            statusLabel: $document->type()->label().' document',
        );
    }

    private function goal(User $user, int $id): ResolvedContext
    {
        $goal = $user->goals()->find($id);

        if ($goal === null) {
            throw new ContextUnavailable;
        }

        return new ResolvedContext(
            type: SupportContextType::Goal,
            id: $goal->getKey(),
            // Their own words for their own goal, made safe to echo back.
            statusLabel: CustomerText::forCopy($goal->title, 'Your goal'),
        );
    }
}
