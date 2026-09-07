<?php

namespace App\Domain\Ai;

/**
 * What FynnAI is allowed to remember between conversations.
 *
 * An allowlist, not a filter. "Remember anything that isn't obviously
 * sensitive" fails the first time something unanticipated arrives; a short
 * list of keys the product actually uses cannot.
 *
 * Financial figures are deliberately absent. They already live on the
 * customer's profile, where they are editable, visible and authoritative —
 * copying them into a memory store would create a second, staler truth.
 */
final class MemoryPolicy
{
    /**
     * key => what it is for.
     *
     * @return array<string, string>
     */
    public static function allowed(): array
    {
        return [
            // What the customer is working towards, so a later conversation
            // does not start by asking again.
            'preferred_goal' => 'The goal the customer is focused on',

            // How they like to be answered.
            'communication_preference' => 'Concise or detailed answers',

            // What they came to FynnEdge for.
            'primary_intent' => 'What the customer is trying to do',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::allowed());
    }

    public static function allows(string $key): bool
    {
        return array_key_exists($key, self::allowed());
    }

    public const MAX_VALUE_LENGTH = 200;
}
