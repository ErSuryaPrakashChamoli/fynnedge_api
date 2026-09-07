<?php

namespace App\Domain\Lender;

/**
 * Whatever eventually receives an application.
 *
 * The domain names no lender. A future adapter for a real gateway, an
 * aggregator, a DSA platform or a direct lender API implements this and
 * nothing above it changes.
 *
 * An adapter authenticates to its provider, transmits the payload, reads the
 * acknowledgement, and turns the provider's failures into canonical ones. It
 * does NOT calculate anything financial, decide eligibility, update a
 * profile, touch FynnScore or FynnMatch, or write to the database.
 */
interface LenderSubmissionProvider
{
    /** False while nothing can receive an application. */
    public function isAvailable(): bool;

    /** How this provider identifies itself in stored records. */
    public function name(): ?string;

    /**
     * The name a customer may be shown, when the contract permits it.
     *
     * Null when there is nothing honest to display. A fixture returns an
     * obviously fictional label; it must never borrow a real lender's name.
     */
    public function displayName(): ?string;

    /**
     * Its contractual role, so a customer is told accurately what they are
     * applying through.
     *
     * A gateway or aggregator is not "a lender", and describing one as a
     * direct lender integration would misdescribe where the application is
     * going.
     */
    public function role(): string;

    /**
     * Whether this provider sends nothing anywhere.
     *
     * Server-side and provider-owned. Nothing a client sends can change it,
     * and it travels unchanged to the app so a simulated submission can
     * never be displayed as a real one.
     */
    public function isSimulated(): bool;

    /**
     * Transmits an application.
     *
     * @param  string  $idempotencyKey  stable across retries of the same
     *                                  attempt, so a provider that supports
     *                                  idempotency can recognise a repeat
     *
     * @throws LenderSubmissionUnavailable
     */
    public function submit(
        LenderApplicationPayload $payload,
        string $idempotencyKey,
    ): ProviderSubmissionResult;
}
