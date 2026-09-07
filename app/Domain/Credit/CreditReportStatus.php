<?php

namespace App\Domain\Credit;

/**
 * Where a customer stands with credit information.
 *
 * Only the states FynnEdge can actually be in. There is no "approved" here
 * and there will not be: a bureau reports what a customer's credit history
 * looks like, and no part of that is a lender's decision.
 */
enum CreditReportStatus: string
{
    /** No bureau provider is configured. Nothing can be requested. */
    case NotConfigured = 'not_configured';

    /** A bureau exists, but the customer has not permitted a request. */
    case ConsentRequired = 'consent_required';

    /** Permitted, and never yet requested. */
    case NotRequested = 'not_requested';

    /** A report was obtained. */
    case Available = 'available';

    /** A request was made and the bureau could not answer it. */
    case Failed = 'failed';

    public function hasReport(): bool
    {
        return $this === self::Available;
    }
}
