<?php

namespace App\Domain\Notifications;

use RuntimeException;

/**
 * A notification was built from something FynnEdge does not put in one.
 *
 * Raised at construction, so the offending text never reaches a row, a
 * response or a push payload. The message names the rule, never the value —
 * an exception that quotes a PAN back has written it to the log the rule
 * exists to keep it out of.
 */
class RefusedNotificationContent extends RuntimeException
{
    public function __construct(public readonly string $rule, string $why)
    {
        parent::__construct("Refused notification content [{$rule}]: {$why}");
    }
}
