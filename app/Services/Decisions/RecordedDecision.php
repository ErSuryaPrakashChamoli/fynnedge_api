<?php

namespace App\Services\Decisions;

use App\Models\DecisionContext;

/** What came of recording an exploration. */
final class RecordedDecision
{
    public function __construct(
        public readonly DecisionContext $decision,

        /** Whether this call created it, rather than recognising it. */
        public readonly bool $wasCreated,
    ) {}
}
