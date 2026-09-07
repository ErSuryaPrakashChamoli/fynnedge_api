<?php

namespace App\Domain\Scan;

/**
 * Where in the document a value came from.
 *
 * Enough for a customer to check the reading against their own document,
 * and no more. No page image, no full-page text, no provider geometry
 * beyond a box — storing those would mean keeping a copy of the document's
 * contents outside the vault to answer a question the snippet already
 * answers.
 */
final class FieldEvidence
{
    public function __construct(
        public readonly ?int $page = null,

        /** The words the value was read from, as they appear. */
        public readonly ?string $snippet = null,

        /** The section heading it sat under, where the provider names one. */
        public readonly ?string $section = null,

        /** Normalized 0..1 box, so the app can point at it on any zoom. */
        public readonly ?array $box = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->page === null
            && $this->snippet === null
            && $this->section === null
            && $this->box === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'page' => $this->page,
            'snippet' => $this->snippet,
            'section' => $this->section,
            'box' => $this->box,
        ], fn ($v) => $v !== null);
    }

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): ?self
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $evidence = new self(
            page: isset($raw['page']) ? (int) $raw['page'] : null,
            snippet: isset($raw['snippet']) ? (string) $raw['snippet'] : null,
            section: isset($raw['section']) ? (string) $raw['section'] : null,
            box: isset($raw['box']) && is_array($raw['box']) ? $raw['box'] : null,
        );

        return $evidence->isEmpty() ? null : $evidence;
    }
}
