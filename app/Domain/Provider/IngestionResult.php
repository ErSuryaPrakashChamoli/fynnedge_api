<?php

namespace App\Domain\Provider;

use Carbon\CarbonInterface;

/**
 * What one ingestion run did, or refused to do.
 */
final class IngestionResult
{
    /**
     * @param  list<array{reference: ?string, stage: string, reason: string}>  $failures
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly ?CarbonInterface $retrievedAt,
        public readonly int $received = 0,
        public readonly int $normalized = 0,
        public readonly int $created = 0,
        /**
         * Versions written: created, or a still-unpublished draft refreshed.
         * A draft nobody has offered yet is updated rather than duplicated —
         * two unpublished records of the same product only leave an operator
         * choosing between them.
         */
        public readonly int $versionsCreated = 0,
        public readonly int $unchanged = 0,
        public readonly int $deactivated = 0,
        public readonly array $failures = [],
        public readonly bool $applied = false,
        public readonly bool $dryRun = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->failures === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->providerCode,
            'retrieved_at' => $this->retrievedAt?->toIso8601String(),
            'received' => $this->received,
            'normalized' => $this->normalized,
            'created' => $this->created,
            'versions_created' => $this->versionsCreated,
            'unchanged' => $this->unchanged,
            'deactivated' => $this->deactivated,
            'failures' => $this->failures,
            'applied' => $this->applied,
            'dry_run' => $this->dryRun,
        ];
    }
}
