<?php

namespace App\Domain\Support;

/**
 * Where a request has got to, and where it may go next.
 *
 * The transitions are the point. Without them a status column is a string
 * anything can write, and "resolved" becomes something a bug can set. With
 * them, every change is checked against what is actually possible, and a
 * customer's request cannot go backwards from resolved to open because a
 * retry ran twice.
 *
 * Nothing here advances on a timer or on a customer's action alone. A case
 * moves because somebody did something, and until FynnEdge has advisors,
 * most cases will sit at `open` — which is the honest thing for them to do.
 * A status machine that walked a case to `in_progress` after five minutes
 * would be inventing work nobody did.
 */
enum SupportCaseStatus: string
{
    /** The customer asked. Nobody has looked yet. */
    case Open = 'open';

    /** FynnEdge has confirmed it has the request. */
    case Acknowledged = 'acknowledged';

    /** Somebody is actually working on it. */
    case InProgress = 'in_progress';

    /** It cannot go further until the customer adds something. */
    case WaitingForCustomer = 'waiting_for_customer';

    /** The thing they asked about has been dealt with. */
    case Resolved = 'resolved';

    /** Finished. Nothing further will happen. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Received',
            self::InProgress => 'In progress',
            self::WaitingForCustomer => 'Needs your reply',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * One sentence the customer reads under the status.
     *
     * None of these promises a time, a person or an outcome. FynnEdge has
     * no advisors on a rota and no service level to quote, so a sentence
     * saying "within 24 hours" would be a number nobody agreed to.
     */
    public function description(): string
    {
        return match ($this) {
            self::Open => 'Your request has been recorded. We will come back to you.',
            self::Acknowledged => 'We have your request and will look into it.',
            self::InProgress => 'We are looking into your request.',
            self::WaitingForCustomer => 'We need some information from you to continue.',
            self::Resolved => 'This request has been resolved.',
            self::Closed => 'This request is closed.',
        };
    }

    /** Nothing further happens to a case in a terminal state. */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /** Whether the customer can still add to it. */
    public function acceptsCustomerMessages(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Where this status may go.
     *
     * Closed is reachable from everywhere because a customer may decide at
     * any point that they no longer need help, and refusing that would be
     * holding a request open on their behalf. Everything else moves
     * forward: a resolved case cannot become open again, because reopening
     * a decision somebody made is a different act with a different record.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::Acknowledged, self::InProgress, self::Closed],
            self::Acknowledged => [self::InProgress, self::WaitingForCustomer, self::Closed],
            self::InProgress => [self::WaitingForCustomer, self::Resolved, self::Closed],
            self::WaitingForCustomer => [self::InProgress, self::Resolved, self::Closed],
            self::Resolved => [self::Closed],
            self::Closed => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
