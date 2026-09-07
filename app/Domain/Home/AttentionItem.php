<?php

namespace App\Domain\Home;

/**
 * One thing about the customer's money that is worth their attention now.
 *
 * Every item is produced by a rule over a figure an engine calculated, and
 * carries the figure it came from. An item nobody can trace back to a number
 * is an item nobody can check.
 */
final class AttentionItem
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_CAUTION = 'caution';

    public const SEVERITY_SERIOUS = 'serious';

    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail,
        public readonly ?string $actionLabel = null,
        public readonly ?string $actionRoute = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity,
            'title' => $this->title,
            'detail' => $this->detail,
            'action_label' => $this->actionLabel,
            'action_route' => $this->actionRoute,
        ];
    }
}
