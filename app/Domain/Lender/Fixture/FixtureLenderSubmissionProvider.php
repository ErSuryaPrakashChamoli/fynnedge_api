<?php

namespace App\Domain\Lender\Fixture;

use App\Domain\Lender\LenderApplicationPayload;
use App\Domain\Lender\LenderSubmissionProvider;
use App\Domain\Lender\LenderSubmissionUnavailable;
use App\Domain\Lender\ProviderSubmissionResult;
use App\Domain\Lender\SubmissionOutcome;
use Illuminate\Support\Carbon;

/**
 * A fictional application submission provider.
 *
 * NO LIVE LENDER SUBMISSION PROVIDER IS CONNECTED TO FYNNEDGE. This exists
 * to prove the boundary works — that a payload is minimized and mapped, that
 * idempotency holds, that an unknown outcome stays unknown, and that a
 * simulated submission is never displayed as a real one.
 *
 * It sends nothing anywhere. It cannot express provider receipt, and it
 * cannot express approval or decline: those need a real provider that
 * actually received and actually decided.
 *
 * Its display name is deliberately, obviously fictional. It must never
 * borrow the name of a real bank or NBFC.
 */
class FixtureLenderSubmissionProvider implements LenderSubmissionProvider
{
    public const NAME = 'fixture';

    /** Obviously not a real institution. */
    public const DISPLAY_NAME = 'FynnEdge Simulated Provider';

    public function __construct(
        private readonly FixtureScenario $scenario = FixtureScenario::Simulated,
    ) {}

    /**
     * False in production, always.
     *
     * This is the one fixture in the product whose output could make a
     * customer believe they have applied for a loan. Every other simulated
     * capability degrades into "we could not do that"; this one, misread,
     * degrades into a person waiting for a lender who has never heard of
     * them.
     *
     * So it is not merely labelled — it refuses to exist in a production
     * build. A deployment that sets the driver to `fixture` in production
     * gets the same answer as one with no provider at all: the capability
     * is unavailable, the consent is not offered, and a submission is
     * refused. There is deliberately no escape hatch.
     */
    public function isAvailable(): bool
    {
        return ! app()->environment('production');
    }

    public function name(): ?string
    {
        return self::NAME;
    }

    public function displayName(): ?string
    {
        return self::DISPLAY_NAME;
    }

    public function role(): string
    {
        return 'simulated';
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function submit(
        LenderApplicationPayload $payload,
        string $idempotencyKey,
    ): ProviderSubmissionResult {
        // Defence in depth: even reached directly, it will not run in a
        // production build.
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'The simulated submission provider cannot run in production.'
            );
        }

        return match ($this->scenario) {
            FixtureScenario::ProviderUnavailable => throw new LenderSubmissionUnavailable(
                LenderSubmissionUnavailable::NOT_SENT,
                'the fixture provider was asked to be unavailable',
            ),

            FixtureScenario::RateLimited => throw new LenderSubmissionUnavailable(
                LenderSubmissionUnavailable::RATE_LIMITED,
                'the fixture provider was asked to rate limit',
            ),

            FixtureScenario::TimeoutAfterSend => throw new LenderSubmissionUnavailable(
                LenderSubmissionUnavailable::TIMEOUT_AFTER_SEND,
                'the fixture provider was asked to time out after sending',
            ),

            FixtureScenario::RejectedByGateway => new ProviderSubmissionResult(
                outcome: SubmissionOutcome::RejectedByGateway,
                provider: self::NAME,
                isSimulated: true,
                at: Carbon::now(),
                rejectionReason: 'incomplete_application',

                // A gateway refusing to forward something is not a lender
                // declining to lend, and the wording must not blur that.
                detail: 'The simulated provider did not accept this '
                    .'application for submission. This is not a lending '
                    .'decision — no lender has seen it.',
            ),

            FixtureScenario::Simulated => ProviderSubmissionResult::simulated(
                self::NAME,
                Carbon::now(),
                'This was a simulated submission. No lender or provider '
                    .'received this application.',
            ),
        };
    }
}
