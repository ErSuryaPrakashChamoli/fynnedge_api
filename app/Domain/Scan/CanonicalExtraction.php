<?php

namespace App\Domain\Scan;

/**
 * What one document appears to contain, in FynnEdge's own vocabulary.
 *
 * No provider field name, no provider confidence scale and no provider
 * error code survives to this point. A different provider — a hosted
 * service, a self-hosted model, a human review desk — produces the same
 * shape or the normalizer refuses its response.
 *
 * This is evidence. Nothing here is the customer's data until the customer
 * says it is.
 */
final class CanonicalExtraction
{
    /**
     * @param  list<ExtractedField>  $fields
     */
    public function __construct(
        public readonly DocumentFamily $family,
        public readonly string $schemaVersion,
        public readonly array $fields,

        /**
         * What the provider thought the document was, in FynnEdge's own
         * family terms. Null when the provider does not classify.
         *
         * Kept separate from the vault's type on purpose: one is a reading,
         * the other is what the customer said when they filed it.
         */
        public readonly ?string $classifiedAs = null,

        /**
         * The period the document covers, where it states one unambiguously.
         * Never conflated with when it was processed.
         */
        public readonly ?string $documentPeriod = null,

        /** The provider's own model identifier, for operations only. */
        public readonly ?string $modelVersion = null,
    ) {}

    public function field(string $key): ?ExtractedField
    {
        foreach ($this->fields as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<ExtractedField> */
    public function fieldsNeedingReview(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (ExtractedField $f) => $f->needsReview(),
        ));
    }

    /**
     * True when every field was read cleanly.
     *
     * A scan is only ever reported as completed when this holds. Anything
     * else is needs_review — a customer must never be shown "completed"
     * over an extraction that is partly missing or partly uncertain.
     */
    public function isComplete(): bool
    {
        return $this->fields !== [] && $this->fieldsNeedingReview() === [];
    }

    /** Whether the reading disagrees with how the customer filed it. */
    public function disagreesWith(DocumentFamily $filedAs): bool
    {
        return $this->classifiedAs !== null
            && $this->classifiedAs !== $filedAs->value;
    }
}
