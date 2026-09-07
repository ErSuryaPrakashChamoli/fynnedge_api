<?php

namespace App\Domain\Match;

/**
 * One checked condition, with the reason and the figures behind it.
 */
final class MatchCriterion
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly CriterionStatus $status,
        public readonly string $detail,
        /** The customer metric or product field this read, if any. */
        public readonly ?string $source = null,
    ) {}

    public static function passed(string $key, string $label, string $detail, ?string $source = null): self
    {
        return new self($key, $label, CriterionStatus::Passed, $detail, $source);
    }

    public static function failed(string $key, string $label, string $detail, ?string $source = null): self
    {
        return new self($key, $label, CriterionStatus::Failed, $detail, $source);
    }

    public static function caution(string $key, string $label, string $detail, ?string $source = null): self
    {
        return new self($key, $label, CriterionStatus::Caution, $detail, $source);
    }

    public static function unavailable(
        string $key,
        string $label,
        string $detail,
        ?string $source = null,
    ): self {
        return new self($key, $label, CriterionStatus::Unavailable, $detail, $source);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'source' => $this->source,
        ];
    }
}
