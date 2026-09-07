<?php

namespace App\Domain\ProviderEvents;

use Illuminate\Http\Request;

/**
 * Proves a delivery actually came from the provider it claims to.
 *
 * Deliberately separate from customer authentication. A provider callback
 * carries no customer session and must never be able to act as one; equally,
 * a customer's token must never authenticate a provider event. They are two
 * different questions about two different parties.
 *
 * A real provider brings its own mechanism — an HMAC over the body, an
 * asymmetric signature, mTLS, a signed JWT — and implements this. None of
 * those is implemented here, because implementing a signature scheme for a
 * provider that does not exist would be inventing a contract.
 */
interface ProviderEventAuthenticator
{
    /** The provider this authenticates for. */
    public function providerCode(): string;

    /** True when a simulated source is behind this. */
    public function isSimulated(): bool;

    /**
     * @throws ProviderEventAuthenticationFailed
     */
    public function authenticate(Request $request): void;
}
