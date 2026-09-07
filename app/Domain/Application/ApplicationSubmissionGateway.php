<?php

namespace App\Domain\Application;

use App\Models\LoanApplication;

/**
 * The boundary between FynnEdge's application layer and whoever actually
 * receives an application.
 *
 * Nothing above this interface knows whether a submission travelled anywhere.
 * The real implementation, when a lender integration exists, goes behind it
 * unchanged; the mock one below it never pretends to be that.
 */
interface ApplicationSubmissionGateway
{
    public function submit(LoanApplication $application): SubmissionResult;

    /** How this gateway identifies itself in stored records. */
    public function name(): string;

    /** True when nothing actually leaves FynnEdge. */
    public function isSimulated(): bool;
}
