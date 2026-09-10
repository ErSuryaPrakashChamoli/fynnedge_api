<?php

namespace App\Domain\Support;

/**
 * What a support request is about, once FynnEdge has checked.
 *
 * Produced only by ContextResolver, from records the customer owns. The
 * fields are a deliberately short list of things that are safe to show back
 * on a support screen and safe to hold in a support table:
 *
 *   a reference the customer already sees elsewhere
 *   a status sentence that comes from Module 28's canonical wording
 *   a product name from the application's own snapshot
 *
 * And nothing else. No document contents, no storage key, no PAN, no
 * extraction, no provider payload, no credit report, no AI conversation.
 * A support case is a request for help, not a second copy of the customer's
 * file.
 */
final class ResolvedContext
{
    public function __construct(
        public readonly SupportContextType $type,

        /** The row this is about, or null for a context with no record. */
        public readonly ?int $id,

        /** What the customer calls it, e.g. FE-2026-000042. */
        public readonly ?string $reference = null,

        /** Module 28's own sentence, never one written here. */
        public readonly ?string $statusLabel = null,

        /** From the application's snapshot, never today's catalogue. */
        public readonly ?string $productName = null,

        /**
         * Whether the customer is stuck on this and cannot resolve it.
         *
         * The only input to priority. True today for exactly one thing: an
         * application whose submission was never confirmed, where FynnEdge
         * has told them not to try again.
         */
        public readonly bool $isBlocked = false,
    ) {}

    public static function bare(SupportContextType $type): self
    {
        return new self($type, null);
    }

    /**
     * What a customer sees on the case.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type' => $this->type->value,
            'label' => $this->type->label(),
            'reference' => $this->reference,
            'status_label' => $this->statusLabel,
            'product_name' => $this->productName,
        ], fn ($v) => $v !== null);
    }
}
