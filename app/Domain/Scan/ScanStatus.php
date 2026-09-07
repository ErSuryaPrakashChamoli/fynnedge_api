<?php

namespace App\Domain\Scan;

/**
 * How far a document's understanding has got.
 *
 * Which of these are reachable depends entirely on configuration. With no
 * provider — the default — only NotStarted and Unavailable occur, and a
 * status claiming otherwise would be the product telling the customer
 * something was read when nothing was.
 *
 * The distinction that matters most is Completed against NeedsReview. A scan
 * is Completed only when every field was read cleanly. A reading that is
 * partly missing, partly uncertain or partly ambiguous is NeedsReview, so a
 * customer is never shown "read" over an extraction that is nothing of the
 * kind.
 */
enum ScanStatus: string
{
    /** Stored, never submitted for understanding. */
    case NotStarted = 'not_started';

    /** No provider is configured, so it cannot be. */
    case Unavailable = 'unavailable';

    /** A provider exists, and the customer has not permitted its use. */
    case ConsentRequired = 'consent_required';

    // Reachable only once a provider exists.
    case Queued = 'queued';
    case Processing = 'processing';

    /** Every field read cleanly. */
    case Completed = 'completed';

    /** Read, with something for the customer to look at. */
    case NeedsReview = 'needs_review';

    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not scanned',
            self::Unavailable => 'Not connected',
            self::ConsentRequired => 'Your permission needed',
            self::Queued => 'Queued',
            self::Processing => 'Reading',
            self::Completed => 'Read',
            self::NeedsReview => 'Please check what we found',
            self::Failed => 'Could not be read',
        };
    }

    /** True where a provider must have reported it. */
    public function requiresProvider(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Processing,
            self::Completed,
            self::NeedsReview,
            self::Failed,
        ], true);
    }

    /** Whether an extraction exists to show. */
    public function hasExtraction(): bool
    {
        return in_array($this, [self::Completed, self::NeedsReview], true);
    }

    /** Whether asking again would just spend another provider call. */
    public function isSettled(): bool
    {
        return in_array($this, [
            self::Completed,
            self::NeedsReview,
            self::Failed,
        ], true);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
