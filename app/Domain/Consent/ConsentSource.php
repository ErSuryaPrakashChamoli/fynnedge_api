<?php

namespace App\Domain\Consent;

/**
 * Where a consent decision was made.
 *
 * Part of the append-only record of how permission was obtained, so it is
 * not free text a client may write. Only FynnEdge's own surfaces record a
 * consent, and each one is named here; anything else would let a caller
 * assert a provenance for a decision that never happened there.
 */
enum ConsentSource: string
{
    /** The privacy screen, where every permission can be reviewed. */
    case PrivacyScreen = 'privacy_screen';

    /** The account setup flow. */
    case Onboarding = 'onboarding';

    /** Acknowledged as part of submitting an application. */
    case Application = 'application';

    /** FynnAI's own memory controls. */
    case AiMemory = 'ai_memory';

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * The source for an untrusted value, defaulting rather than throwing:
     * a decision the customer actually made must still be recorded when the
     * client labels it oddly.
     */
    public static function fromInput(mixed $value): self
    {
        return is_string($value)
            ? (self::tryFrom($value) ?? self::PrivacyScreen)
            : self::PrivacyScreen;
    }
}
