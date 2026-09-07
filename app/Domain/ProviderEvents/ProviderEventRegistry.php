<?php

namespace App\Domain\ProviderEvents;

use App\Domain\ProviderEvents\Fixture\FixtureProviderEventAuthenticator;
use App\Domain\ProviderEvents\Fixture\FixtureProviderEventNormalizer;

/**
 * Which providers FynnEdge accepts events from.
 *
 * An allowlist, deliberately. The provider code arrives in the URL, and a
 * request must never be able to name an internal class, a driver or
 * anything else that decides how it is handled — it can only match a code
 * that is already known here, or be refused.
 *
 * An unknown code is a plain 404 with nothing in it: whether FynnEdge
 * happens to integrate with a given provider is not something an
 * unauthenticated caller gets to learn.
 */
final class ProviderEventRegistry
{
    /**
     * The configured sources.
     *
     * @return array<string, array{
     *     authenticator: ProviderEventAuthenticator,
     *     normalizer: ProviderEventNormalizer
     * }>
     */
    public static function sources(): array
    {
        $sources = [];

        /*
         * The simulated source, present only when explicitly enabled and
         * never in production.
         *
         * A production build has no entry for it at all, so a fixture
         * delivery there does not fail authentication — the provider simply
         * does not exist, and the request is a 404 like any other unknown
         * code.
         */
        if (self::fixtureEnabled()) {
            $sources[FixtureProviderEventAuthenticator::PROVIDER_CODE] = [
                'authenticator' => new FixtureProviderEventAuthenticator,
                'normalizer' => new FixtureProviderEventNormalizer,
            ];
        }

        return $sources;
    }

    public static function has(string $providerCode): bool
    {
        return array_key_exists($providerCode, self::sources());
    }

    /**
     * @return array{
     *     authenticator: ProviderEventAuthenticator,
     *     normalizer: ProviderEventNormalizer
     * }|null
     */
    public static function for(string $providerCode): ?array
    {
        return self::sources()[$providerCode] ?? null;
    }

    private static function fixtureEnabled(): bool
    {
        return ! app()->environment('production')
            && config('fynnedge.provider_events.driver', 'none') === 'fixture';
    }

    private function __construct() {}
}
