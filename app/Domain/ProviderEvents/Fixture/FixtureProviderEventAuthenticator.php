<?php

namespace App\Domain\ProviderEvents\Fixture;

use App\Domain\ProviderEvents\ProviderEventAuthenticationFailed;
use App\Domain\ProviderEvents\ProviderEventAuthenticator;
use Illuminate\Http\Request;

/**
 * A deterministic authenticator for the simulated event source.
 *
 * NO LIVE PROVIDER EVENT SOURCE IS CONNECTED TO FYNNEDGE. This exists to
 * prove the ingress boundary works — that an unsigned delivery is refused,
 * that a wrong secret is refused, that a replayed one is caught.
 *
 * It is a real check, not a bypass. There is deliberately no "accept
 * everything while testing" branch: a boundary that stops working under
 * test configuration is a boundary nobody has actually tested.
 *
 * The mechanism is a plain shared secret compared in constant time, chosen
 * because it is the simplest thing that is genuinely a check. It is NOT a
 * proposal for what a real provider should use — that comes from the
 * provider's contract, and would be an HMAC over the body, an asymmetric
 * signature or mTLS.
 */
class FixtureProviderEventAuthenticator implements ProviderEventAuthenticator
{
    public const PROVIDER_CODE = 'fixture';

    public const HEADER = 'X-FynnEdge-Fixture-Secret';

    public function providerCode(): string
    {
        return self::PROVIDER_CODE;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function authenticate(Request $request): void
    {
        // The simulated source cannot exist in production at all, so there
        // is no configuration in which a fixture delivery could reach a
        // real customer's application there.
        if (app()->environment('production')) {
            throw new ProviderEventAuthenticationFailed('fixture_in_production');
        }

        $expected = (string) config('fynnedge.provider_events.fixture_secret', '');

        if ($expected === '') {
            // No secret configured means no way to authenticate, which
            // means nothing is accepted. Never the other way round.
            throw new ProviderEventAuthenticationFailed('no_secret_configured');
        }

        $presented = (string) $request->header(self::HEADER, '');

        if (! hash_equals($expected, $presented)) {
            throw new ProviderEventAuthenticationFailed;
        }
    }
}
