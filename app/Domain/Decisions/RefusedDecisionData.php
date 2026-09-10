<?php

namespace App\Domain\Decisions;

use RuntimeException;

/**
 * Something was about to be written into a decision record that does not
 * belong there.
 *
 * The message names the field and the rule, never the value — an exception
 * quoting a PAN has written it to the log the rule exists to keep it out of.
 */
class RefusedDecisionData extends RuntimeException
{
    public function __construct(public readonly string $field, string $why)
    {
        parent::__construct("Refused decision data [{$field}]: {$why}");
    }
}
