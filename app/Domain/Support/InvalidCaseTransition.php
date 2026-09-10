<?php

namespace App\Domain\Support;

use RuntimeException;

/** A support case cannot go from where it is to where something asked. */
class InvalidCaseTransition extends RuntimeException
{
    public function __construct(
        public readonly SupportCaseStatus $from,
        public readonly SupportCaseStatus $to,
    ) {
        parent::__construct(
            "A support case cannot move from [{$from->value}] to [{$to->value}]."
        );
    }
}
