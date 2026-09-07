<?php

namespace App\Domain\Notifications;

/**
 * What a customer will be told, decided once and checked here.
 *
 * Every notification in FynnEdge is built as one of these. That makes this
 * the canonical boundary — the single object every producer must construct
 * — and Modules 23–28 taught the same lesson three times: a policy written
 * down in a map, and enforced only by the one caller that happens to obey
 * it, protects nothing once a second caller exists.
 *
 * So two things are taken away from callers entirely:
 *
 *   category         — derived from the type, never passed
 *   transactional    — derived from the type, never passed
 *
 * A caller cannot classify an "action required" message as optional, and
 * cannot file a document problem under goals. Both are properties of what
 * happened, not of who is reporting it.
 *
 * And two things are refused outright:
 *
 *   a link this app cannot open
 *   text that looks like data a notification must never carry
 *
 * The second is a net, not a proof — see refuseSensitiveText(). It cannot
 * recognise every sensitive value, and it is not the reason notification
 * copy is safe; the reason is that the policy writes the copy from a small
 * set of known-safe sentences. This catches the day somebody writes a
 * message by interpolating a provider field.
 */
final class NotificationDecision
{
    public const MAX_TITLE = 120;

    public const MAX_BODY = 400;

    private function __construct(
        public readonly NotificationType $type,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $deepLink,

        /**
         * What makes this notification the same notification.
         *
         * Two deliveries of one provider event, two retries of one job and
         * two taps of one button must all produce this same string, because
         * a unique index on it is what makes exactly one row exist.
         */
        public readonly string $idempotencyKey,
    ) {}

    public static function of(
        NotificationType $type,
        string $title,
        string $body,
        string $idempotencyKey,
        ?string $deepLink = null,
    ): self {
        self::refuseEmpty('title', $title, self::MAX_TITLE);
        self::refuseEmpty('body', $body, self::MAX_BODY);
        self::refuseEmpty('idempotency_key', $idempotencyKey, 190);

        if ($deepLink !== null && ! DeepLink::isValid($deepLink)) {
            throw new RefusedNotificationContent(
                'deep_link',
                'the link is not one the FynnEdge router can open. Build '
                .'links with DeepLink so a notification cannot point at a '
                .'screen that does not exist, or carry anything in a path.',
            );
        }

        self::refuseSensitiveText('title', $title);
        self::refuseSensitiveText('body', $body);

        return new self($type, $title, $body, $deepLink, $idempotencyKey);
    }

    public function category(): NotificationCategory
    {
        return $this->type->category();
    }

    public function isTransactional(): bool
    {
        return $this->type->isTransactional();
    }

    private static function refuseEmpty(string $field, string $value, int $max): void
    {
        if (trim($value) === '') {
            throw new RefusedNotificationContent($field, 'it is empty');
        }

        if (mb_strlen($value) > $max) {
            throw new RefusedNotificationContent(
                $field,
                "it is longer than {$max} characters; notification copy is "
                .'a sentence, not a report',
            );
        }
    }

    /**
     * Text that looks like something a notification must never carry.
     *
     * Each pattern names a class of value Modules 23–28 established FynnEdge
     * does not put in front of a customer twice or hold where it does not
     * need to. The rule name travels in the exception; the value never does.
     *
     * This is a net and not a guarantee. A nine-digit account number is
     * caught, a six-digit one is not, and no regular expression can
     * recognise a customer's address. What it does reliably is fail the
     * moment somebody interpolates a raw provider field into copy, which is
     * exactly how sensitive data reaches a screen by accident.
     */
    private static function refuseSensitiveText(string $field, string $value): void
    {
        $rules = [
            'pan' => '/\b[A-Z]{5}[0-9]{4}[A-Z]\b/',

            // Aadhaar and long account or document numbers alike. FynnEdge
            // copy quotes no identifier this long; an amount is written with
            // separators and a reference carries letters.
            'long_number' => '/[0-9]{9,}/',

            // A vault storage key, in either half of its shape.
            'storage_key' => '#documents/u[0-9]+/|\b[0-9A-HJKMNP-TV-Z]{26}\.(pdf|jpe?g|png|heic|webp)\b#i',

            // A content digest, a payload hash, or anything else that is
            // 64 hex characters and therefore not a sentence.
            'digest' => '/\b[0-9a-f]{64}\b/i',

            // An API token, in the shape Sanctum issues and in the shape a
            // header carries.
            'token' => '/\b[0-9]+\|[A-Za-z0-9]{20,}\b|\bBearer\s+\S{16,}/i',
        ];

        foreach ($rules as $rule => $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new RefusedNotificationContent(
                    $rule,
                    "the {$field} contains something shaped like a value "
                    .'FynnEdge does not put in a notification',
                );
            }
        }
    }
}
