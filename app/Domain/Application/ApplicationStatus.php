<?php

namespace App\Domain\Application;

/**
 * Where an application stands.
 *
 * The first five are FynnEdge's own: it knows whether a customer has started
 * an application, sent it, or called it off. The last three describe what a
 * provider decided, and FynnEdge cannot know that — nothing in this codebase
 * may set them except a real gateway relaying a real provider response. The
 * mock gateway does not, and a test enforces it.
 */
enum ApplicationStatus: string
{
    /** Created from a match, not yet sent. */
    case Started = 'started';

    /** Handed to a submission gateway and acknowledged. */
    case Submitted = 'submitted';

    /** A provider is assessing it. Only a provider response sets this. */
    case UnderReview = 'under_review';

    /** Something is needed from the customer. Set by a provider response. */
    case ActionNeeded = 'action_needed';

    /** The customer withdrew it. */
    case Cancelled = 'cancelled';

    /** Provider decisions. FynnEdge never writes these itself. */
    case Approved = 'approved';
    case Declined = 'declined';
    case Disbursed = 'disbursed';

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Started',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under Review',
            self::ActionNeeded => 'Action Needed',
            self::Cancelled => 'Cancelled',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Disbursed => 'Disbursed',
        };
    }

    /** True where the status reports a provider's decision, not FynnEdge's. */
    public function isProviderDecision(): bool
    {
        return in_array($this, [
            self::UnderReview,
            self::ActionNeeded,
            self::Approved,
            self::Declined,
            self::Disbursed,
        ], true);
    }

    /** Statuses FynnEdge itself can move an application into. */
    public function isSetByFynnEdge(): bool
    {
        return ! $this->isProviderDecision();
    }

    /** Nothing more will happen to this application. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Declined,
            self::Disbursed,
            self::Cancelled,
        ], true);
    }

    /** Still editable: only a draft can have its figures changed. */
    public function isEditable(): bool
    {
        return $this === self::Started;
    }

    public function canSubmit(): bool
    {
        return $this === self::Started;
    }

    /** Nothing sent yet, or sent but no provider has ruled on it. */
    public function canCancel(): bool
    {
        return ! $this->isTerminal();
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /** What the customer should do next, where FynnEdge can say. */
    public function nextAction(): string
    {
        return match ($this) {
            self::Started => 'Review the details and submit when you are ready.',
            self::Submitted => 'Nothing to do. We will tell you when the provider responds.',
            self::Cancelled => 'This application was cancelled. You can start a new one at any time.',
            default => '',
        };
    }
}
