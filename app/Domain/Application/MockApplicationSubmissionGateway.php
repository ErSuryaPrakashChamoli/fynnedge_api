<?php

namespace App\Domain\Application;

use App\Models\LoanApplication;
use Illuminate\Support\Carbon;

/**
 * A gateway that accepts an application and sends it nowhere.
 *
 * It acknowledges receipt and stops there. It does not move the application
 * to Under Review, and it never approves or declines one: those are a
 * provider's to report, no provider has seen this, and a simulated decision
 * on a screen is indistinguishable from a real one to the person reading it.
 *
 * Everything it produces is stamped simulated.
 */
class MockApplicationSubmissionGateway implements ApplicationSubmissionGateway
{
    public const NAME = 'mock';

    public function submit(LoanApplication $application): SubmissionResult
    {
        return new SubmissionResult(
            accepted: true,
            status: ApplicationStatus::Submitted,
            gateway: self::NAME,
            isSimulated: true,
            at: Carbon::now(),
            // No provider reference: no provider received this.
            providerReference: null,
            detail: 'Recorded by FynnEdge. No provider has received this '
                .'application: lender submission is not connected yet.',
        );
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return true;
    }
}
