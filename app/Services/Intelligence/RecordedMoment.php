<?php

namespace App\Services\Intelligence;

use App\Models\FinancialMoment;

/** What came of recording a moment. */
final class RecordedMoment
{
    public function __construct(
        public readonly FinancialMoment $moment,

        /**
         * Whether this call created it.
         *
         * False means the moment already existed — the customer refreshed,
         * or a job ran twice. Anything that should happen once per moment
         * reads this rather than the row's existence.
         */
        public readonly bool $wasCreated,
    ) {}
}
