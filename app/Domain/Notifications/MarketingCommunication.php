<?php

namespace App\Domain\Notifications;

use App\Domain\Consent\ConsentType;

/**
 * Why FynnEdge sends no marketing, and what that must not be confused with.
 *
 * This class holds no logic. It exists because the distinction it records
 * is the one most easily lost: a product that treats "notifications" as a
 * single switch ends up letting a customer who declined promotional email
 * miss the message telling them their application needs a document.
 *
 * Two kinds of message, two different reasons for sending them:
 *
 * TRANSACTIONAL / SERVICE — the customer asked FynnEdge to do something and
 * this is what happened. An application status, a document that cannot be
 * accepted, an action a provider needs from them. Sent because they are
 * using the product, not because they agreed to hear from it. Never gated
 * on marketing consent, and NotificationType::isTransactional() decides
 * which these are, from the event rather than from the caller.
 *
 * MARKETING — a message FynnEdge sends because it wants to, not because
 * the customer did something. Promotions, cross-sell, campaigns.
 *
 * FYNNEDGE SENDS NO MARKETING COMMUNICATION. Not because it has been
 * switched off, but because nothing in this codebase composes, schedules or
 * sends one, and there is no channel to send it on. ConsentType::MarketingMessages
 * already exists and already reports itself unavailable for exactly that
 * reason. Nothing in Module 29 changes that, and no notification type here
 * is promotional.
 *
 * If marketing is ever built, it belongs in its own module with its own
 * store, its own scheduling and its own consent check — never by adding a
 * category to NotificationCategory, because that is the change that makes
 * one preference able to suppress the other kind.
 */
final class MarketingCommunication
{
    /** The consent that governs marketing, and only marketing. */
    public const CONSENT = ConsentType::MarketingMessages;

    /**
     * Whether FynnEdge can send marketing communication at all.
     *
     * False, and not configurable: there is nothing to configure. Stated as
     * a method so a test can assert it rather than a reader having to trust
     * a comment.
     */
    public static function isAvailable(): bool
    {
        return false;
    }

    public static function unavailableReason(): string
    {
        return 'FynnEdge has no marketing communication. Nothing composes, '
            .'schedules or sends a promotional message, and there is no '
            .'channel to send one on.';
    }
}
