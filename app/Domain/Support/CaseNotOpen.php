<?php

namespace App\Domain\Support;

use RuntimeException;

/** Nothing further can be added to a closed request. */
class CaseNotOpen extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That request is closed.');
    }
}
