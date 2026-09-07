<?php

namespace App\Domain\Kyc;

/**
 * How one identity field compared.
 *
 * Values travel only where the customer needs them to resolve a difference.
 * A matched field carries no values at all — the customer already knows what
 * is on their profile, and repeating a verified identity value back at them
 * puts it on a screen for no reason.
 */
final class FieldMatch
{
    public function __construct(
        public readonly string $field,
        public readonly string $label,
        public readonly MatchStatus $status,

        /** Why, when the answer is not simply matched. */
        public readonly ?string $reason = null,

        /** What FynnEdge holds. Present only on a mismatch. */
        public readonly ?string $profileValue = null,

        /** What the service returned. Present only on a mismatch. */
        public readonly ?string $verifiedValue = null,
    ) {}

    public static function matched(string $field, string $label): self
    {
        return new self($field, $label, MatchStatus::Matched);
    }

    public static function mismatched(
        string $field,
        string $label,
        string $profileValue,
        string $verifiedValue,
    ): self {
        return new self(
            field: $field,
            label: $label,
            status: MatchStatus::Mismatched,
            profileValue: $profileValue,
            verifiedValue: $verifiedValue,
        );
    }

    public static function unableToCompare(
        string $field,
        string $label,
        string $reason,
    ): self {
        return new self(
            field: $field,
            label: $label,
            status: MatchStatus::UnableToCompare,
            reason: $reason,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'label' => $this->label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'reason' => $this->reason,

            // Shown only where a customer has a difference to resolve.
            'profile_value' => $this->profileValue,
            'verified_value' => $this->verifiedValue,
        ];
    }
}
