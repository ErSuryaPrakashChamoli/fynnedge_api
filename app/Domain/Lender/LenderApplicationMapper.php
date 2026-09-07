<?php

namespace App\Domain\Lender;

use App\Models\LoanApplication;

/**
 * FynnEdge's application into a provider's vocabulary.
 *
 * The mapper owns provider field names, provider formatting, provider
 * product codes and provider enum values — and nothing else. It does not
 * calculate an EMI, evaluate eligibility, touch FynnScore or FynnMatch, or
 * update a profile.
 *
 * It also owns the minimization decision: a mapper builds the payload from
 * named sources, so nothing reaches a provider by having been in scope.
 */
interface LenderApplicationMapper
{
    /**
     * @throws ProviderMappingMissing when the product has no provider code
     */
    public function map(LoanApplication $application): LenderApplicationPayload;
}
