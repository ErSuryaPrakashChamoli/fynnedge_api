<?php

namespace App\Domain\Twin;

/**
 * One thing worth noticing about a projection.
 *
 * Each is derived from a figure the engine produced, and each carries that
 * figure. A warning nobody can trace back to a number is a warning nobody
 * can check.
 */
final class TwinObservation
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_CAUTION = 'caution';

    public const SEVERITY_SERIOUS = 'serious';

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $detail,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'detail' => $this->detail,
        ];
    }
}
