<?php

namespace App\Domain\Decisions;

/**
 * One figure that went into a result, and where it came from.
 *
 * Every number a customer is shown in an explanation is one of these, so
 * every number carries its provenance. That is the point of the module: a
 * customer looking at "₹22,881" can see that FynnEdge calculated it, that
 * the 13.25% behind it was published by a lender, and that the ₹10,00,000
 * was something they typed.
 */
final class ExplanationFactor
{
    public function __construct(
        public readonly string $label,

        /** Already formatted for reading. */
        public readonly string $value,

        public readonly DecisionSource $source,

        /** One line, when the figure needs one. */
        public readonly ?string $note = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'label' => $this->label,
            'value' => $this->value,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'note' => $this->note,
        ], fn ($v) => $v !== null);
    }
}
