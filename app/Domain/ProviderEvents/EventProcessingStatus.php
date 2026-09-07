<?php

namespace App\Domain\ProviderEvents;

/**
 * What became of one provider event.
 *
 * Nothing is silently discarded: every delivery that authenticates gets a
 * row and one of these, so an operator can always answer "what happened to
 * the event the provider says it sent?".
 */
enum EventProcessingStatus: string
{
    /** Authenticated and stored, not yet applied. */
    case Received = 'received';

    /** Applied. The application or its submission moved. */
    case Processed = 'processed';

    /** Already seen. No second effect. */
    case Duplicate = 'duplicate';

    /**
     * Valid, and it changes nothing.
     *
     * A stale event, one contradicting a decision already recorded, or one
     * about a stage the application is already past. Kept rather than
     * dropped, because "the provider sent this and we did not act on it" is
     * exactly what an operator needs to see.
     */
    case Ignored = 'ignored';

    /** Failed authentication or structural validation. */
    case Rejected = 'rejected';

    /** Accepted, and could not be applied. Needs an operator. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Processed => 'Processed',
            self::Duplicate => 'Duplicate',
            self::Ignored => 'Ignored',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }
}
