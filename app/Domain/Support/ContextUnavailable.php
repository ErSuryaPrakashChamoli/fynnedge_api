<?php

namespace App\Domain\Support;

use RuntimeException;

/**
 * The thing a support request said it was about cannot be used.
 *
 * One exception for three different situations — it does not exist, it is
 * not this customer's, or the type is not one FynnEdge supports — and
 * deliberately so. Distinguishing them in a response would tell somebody
 * probing ids which of another customer's applications are real.
 */
class ContextUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That is not something we can attach to a request.');
    }
}
