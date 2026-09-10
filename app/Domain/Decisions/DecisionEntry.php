<?php

namespace App\Domain\Decisions;

use Illuminate\Support\Carbon;

/**
 * One entry in a customer's decision history, whatever it came from.
 *
 * Two sources produce these: the `decision_contexts` table, and the
 * customer's own applications. They are kept in one shape here rather than
 * two so the history can be read in time order without a caller having to
 * know which is which — and because an application genuinely is a decision
 * context, complete with the terms shown at the time.
 *
 * `isSimulated` is carried on every entry rather than inferred from the
 * type. A simulation stays labelled a simulation everywhere it is read,
 * including on a screen written later by somebody who did not read this
 * file.
 */
final class DecisionEntry
{
    public function __construct(
        /** Prefixed, so a decision id and an application id cannot collide. */
        public readonly string $id,

        public readonly DecisionType $type,
        public readonly Carbon $occurredAt,
        public readonly DecisionSnapshot $snapshot,

        public readonly ?string $productCode = null,
        public readonly ?string $productName = null,
        public readonly ?int $productVersionId = null,

        /** The customer-facing reference, when the entry has one. */
        public readonly ?string $reference = null,

        /** True of nothing that has happened. */
        public readonly bool $isSimulated = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'type' => $this->type->value,
            'title' => $this->type->label(),
            'description' => $this->type->description(),
            'occurred_at' => $this->occurredAt->toIso8601String(),

            'product' => $this->productCode === null ? null : array_filter([
                'code' => $this->productCode,
                'name' => $this->productName,
                // The version is what makes the entry historically stable.
                // A catalogue change tomorrow does not change what was
                // shown today, because the version that showed it is named.
                'version' => $this->productVersionId,
            ], fn ($v) => $v !== null),

            'reference' => $this->reference,
            'is_simulated' => $this->isSimulated,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
