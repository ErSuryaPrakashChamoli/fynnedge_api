<?php

namespace App\Domain\Catalog;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The provenance of one product record.
 *
 * Module 19 established that no figure may masquerade as another. This is
 * where a product says which kind it is, so nothing downstream has to guess
 * from a lender's name or from which repository happened to answer.
 */
final class ProductSource
{
    private function __construct(
        public readonly ProductSourceType $type,

        /**
         * What to look at to check this record — a provider's rate card, a
         * feed identifier. Null for sample data, which references nothing
         * because it describes nothing real.
         */
        public readonly ?string $reference,

        /** When FynnEdge obtained it. Null when never retrieved. */
        public readonly ?CarbonInterface $retrievedAt,
    ) {}

    /**
     * Internally written demonstration data.
     *
     * Deliberately carries no reference and no retrieval time: there is
     * nothing to check it against and nothing was fetched.
     */
    public static function sample(): self
    {
        return new self(ProductSourceType::Sample, null, null);
    }

    public static function manual(
        string $reference,
        ?CarbonInterface $retrievedAt = null,
    ): self {
        return new self(
            ProductSourceType::Manual,
            $reference,
            $retrievedAt ?? Carbon::now(),
        );
    }

    public static function fromProvider(
        ProductSourceType $type,
        string $reference,
        CarbonInterface $retrievedAt,
    ): self {
        if ($type === ProductSourceType::Sample) {
            throw new \InvalidArgumentException(
                'Sample data has no provider; use ProductSource::sample().',
            );
        }

        return new self($type, $reference, $retrievedAt);
    }

    public function isLive(): bool
    {
        return $this->type->isLive();
    }

    /**
     * What the customer is told.
     *
     * Only the sample/live distinction crosses the API. A reference and a
     * retrieval time are operational detail: useful to whoever maintains the
     * catalogue, meaningless and slightly alarming on a decision screen.
     *
     * @return array<string, mixed>
     */
    public function toCustomerArray(): array
    {
        return ['is_sample_data' => ! $this->isLive()];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'reference' => $this->reference,
            'retrieved_at' => $this->retrievedAt?->toIso8601String(),
            'is_live' => $this->isLive(),
        ];
    }
}
