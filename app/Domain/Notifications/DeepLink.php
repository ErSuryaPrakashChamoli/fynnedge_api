<?php

namespace App\Domain\Notifications;

/**
 * Where a notification takes the customer.
 *
 * One contract, shared by the API and the Flutter router. Every link is a
 * `fynnedge://` URI naming a destination that already exists in the app's
 * router — this class does not describe a second navigation system, it
 * describes the one FynnEdge has.
 *
 * Deliberately absent: a link to a single goal. FynnEdge has a goals screen
 * and no per-goal screen, so `fynnedge://goals/{id}` would be a link to
 * nowhere. A goal milestone opens the goals list, which is where the
 * customer can actually see it.
 *
 * Links are built here and nowhere else, and NotificationDecision refuses
 * anything this class did not produce. That is what stops a link becoming a
 * place to smuggle an identifier or a value into a notification.
 */
final class DeepLink
{
    public const SCHEME = 'fynnedge://';

    /**
     * The shapes a link may take.
     *
     * Each is a full-string pattern. An identifier may be digits only, which
     * is what every customer-facing id in FynnEdge already is; nothing else
     * may appear in a path.
     *
     * @var list<string>
     */
    private const SHAPES = [
        '#^fynnedge://applications$#',
        '#^fynnedge://applications/[1-9][0-9]{0,17}$#',
        '#^fynnedge://vault$#',
        '#^fynnedge://vault/[1-9][0-9]{0,17}$#',
        '#^fynnedge://fynn-score$#',
        '#^fynnedge://goals$#',
        '#^fynnedge://support$#',
    ];

    public static function applications(): string
    {
        return self::SCHEME.'applications';
    }

    public static function application(int|string $id): string
    {
        return self::SCHEME.'applications/'.$id;
    }

    public static function vault(): string
    {
        return self::SCHEME.'vault';
    }

    /** One stored document, which in the app is its FynnScan screen. */
    public static function document(int|string $id): string
    {
        return self::SCHEME.'vault/'.$id;
    }

    public static function fynnScore(): string
    {
        return self::SCHEME.'fynn-score';
    }

    public static function goals(): string
    {
        return self::SCHEME.'goals';
    }

    public static function support(): string
    {
        return self::SCHEME.'support';
    }

    /** Whether a string is a link this contract recognises. */
    public static function isValid(?string $link): bool
    {
        if ($link === null) {
            return false;
        }

        foreach (self::SHAPES as $shape) {
            if (preg_match($shape, $link) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every shape, for tests and for the Flutter mirror.
     *
     * @return list<string>
     */
    public static function shapes(): array
    {
        return self::SHAPES;
    }
}
