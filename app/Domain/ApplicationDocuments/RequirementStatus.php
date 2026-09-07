<?php

namespace App\Domain\ApplicationDocuments;

/**
 * Where one required document stands for one application.
 *
 * Deliberately not a boolean. "Verified" would be ambiguous — verified by
 * whom, against what? — and FynnEdge verifies nothing here: it records which
 * document the customer chose and whether they have said it is the right
 * one.
 *
 * None of these states says anything about a lender. A document can be Ready
 * and have been seen by nobody outside FynnEdge, which is exactly the
 * situation today.
 */
enum RequirementStatus: string
{
    /** No document has been chosen for this requirement. */
    case Missing = 'missing';

    /**
     * A document is attached and the customer has not confirmed it.
     *
     * Choosing a file is not the same as saying "this is the one". The
     * confirmation is a separate act.
     */
    case Available = 'available';

    /**
     * Something changed and the customer needs to look again.
     *
     * Set when a confirmed document's file is no longer what it was — the
     * confirmation described something else, so it does not carry over.
     */
    case NeedsReview = 'needs_review';

    /** The customer has confirmed this is the document to use. */
    case Ready = 'ready';

    /**
     * The customer looked and said this document will not do.
     *
     * A record of their judgement, not FynnEdge's. Nothing here assesses a
     * document's contents or quality.
     */
    case NotSuitable = 'not_suitable';

    public function label(): string
    {
        return match ($this) {
            self::Missing => 'Not added yet',
            self::Available => 'Needs your confirmation',
            self::NeedsReview => 'Needs your review',
            self::Ready => 'Ready for your application',
            self::NotSuitable => 'Marked as unsuitable',
        };
    }

    /** What the customer has to do about it, if anything. */
    public function action(): ?string
    {
        return match ($this) {
            self::Missing => 'Add from FynnVault',
            self::Available => 'Confirm this document',
            self::NeedsReview => 'Check this document again',
            self::NotSuitable => 'Choose another document',
            self::Ready => null,
        };
    }

    /** Whether a required document in this state lets an application be ready. */
    public function satisfiesRequirement(): bool
    {
        return $this === self::Ready;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
