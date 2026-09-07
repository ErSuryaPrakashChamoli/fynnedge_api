<?php

namespace App\Domain\Notifications;

/**
 * A customer's own words, made safe to put back in front of them.
 *
 * NotificationDecision refuses copy containing anything shaped like a PAN,
 * a long number or a digest. That rule is aimed at FynnEdge: it catches the
 * day somebody interpolates a provider field into a sentence.
 *
 * Customer-entered text is a different thing. A goal called "Deposit
 * 123456789" is the customer's own label for their own goal, disclosing
 * nothing they do not already know — but it would trip the same rule and
 * cost them a notification they should have had. Refusing to name their
 * goal is not a privacy win; it is a bug.
 *
 * So a fragment of customer text is neutralised here before it becomes
 * copy: shortened to a phrase, stripped of control characters, and with
 * long digit runs shortened so a title cannot be mistaken for an
 * identifier. What comes out is recognisably theirs and cannot trip a rule
 * meant for FynnEdge's own mistakes.
 */
final class CustomerText
{
    private const MAX = 40;

    /**
     * @param  string  $fallback  used when there is nothing usable left
     */
    public static function forCopy(?string $text, string $fallback): string
    {
        $clean = preg_replace('/[\p{C}]+/u', ' ', (string) $text) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ($clean === '') {
            return $fallback;
        }

        // Long digit runs become a short one. The customer still recognises
        // their own label; it can no longer read as an account number, and
        // it can no longer trip a rule written for FynnEdge's own data.
        $clean = preg_replace('/[0-9]{9,}/', '…', $clean) ?? $clean;

        if (mb_strlen($clean) > self::MAX) {
            $clean = rtrim(mb_substr($clean, 0, self::MAX - 1)).'…';
        }

        return $clean === '' || $clean === '…' ? $fallback : $clean;
    }
}
