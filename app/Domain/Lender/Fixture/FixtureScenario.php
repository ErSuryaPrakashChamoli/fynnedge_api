<?php

namespace App\Domain\Lender\Fixture;

/**
 * What the fictional submission provider does when asked.
 *
 * Chosen by server-side configuration and by tests. Deliberately not
 * selectable from a request: a customer able to pick an outcome would be
 * writing their own submission result.
 *
 * There is no `provider_received` and no `approved` scenario, and no way to
 * add one — a fictional provider has received nothing and decided nothing.
 */
enum FixtureScenario: string
{
    /** The simulated channel accepted it. Nothing left FynnEdge. */
    case Simulated = 'simulated';

    /** The gateway refused it before any lender saw it. */
    case RejectedByGateway = 'rejected_by_gateway';

    /** The gateway is down. Nothing was sent. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The gateway is rate limiting. Nothing was sent. */
    case RateLimited = 'rate_limited';

    /**
     * The request left and nothing came back.
     *
     * The scenario that matters most: it must produce Unknown, never Failed,
     * and must not offer the customer a retry button.
     */
    case TimeoutAfterSend = 'timeout_after_send';
}
