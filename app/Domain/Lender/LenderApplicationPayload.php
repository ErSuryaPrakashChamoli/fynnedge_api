<?php

namespace App\Domain\Lender;

/**
 * Exactly what FynnEdge would send about one application.
 *
 * Built by a mapper from an application's historical snapshot and the
 * customer's own declared details. Never from `Application::toArray()`, and
 * never from a live catalogue lookup — a provider must see the terms the
 * customer applied under, not today's.
 *
 * Every field here appears in SubmissionFieldMap::SENT with a purpose, and
 * construction refuses anything else. That refusal lives here rather than
 * in the one mapper that happens to behave, because this object is what
 * every mapper must produce: the next one is written against a real
 * lender's field list by someone reading that lender's documentation, and
 * a PAN or a credit score added there must not be able to reach a provider
 * merely because nobody thought to check.
 */
final class LenderApplicationPayload
{
    /**
     * @param  array<string, mixed>  $fields  canonical, already minimized
     */
    private function __construct(
        public readonly string $fynnEdgeReference,
        public readonly string $providerProductCode,
        public readonly array $fields,
    ) {}

    /** @param array<string, mixed> $fields */
    public static function of(
        string $fynnEdgeReference,
        string $providerProductCode,
        array $fields,
    ): self {
        self::refuseWhatFynnEdgeDoesNotSend($fields);

        return new self($fynnEdgeReference, $providerProductCode, $fields);
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws PayloadNotPermitted
     */
    private static function refuseWhatFynnEdgeDoesNotSend(array $fields): void
    {
        foreach (array_keys($fields) as $field) {
            $field = (string) $field;

            if (isset(SubmissionFieldMap::REFUSED[$field])) {
                throw new PayloadNotPermitted(
                    $field,
                    SubmissionFieldMap::REFUSED[$field],
                );
            }

            if (! in_array($field, SubmissionFieldMap::sentKeys(), true)) {
                // Refused by default rather than allowed by omission. A
                // field nobody has written a purpose for is a field nobody
                // has decided to send.
                throw new PayloadNotPermitted(
                    $field,
                    'it is not listed in SubmissionFieldMap::SENT, so no '
                    .'purpose has been written for sending it',
                );
            }
        }
    }

    /**
     * The keys actually present, for auditing what left.
     *
     * FynnEdge records which fields it sent, never their values. Keeping a
     * copy of an outbound payload would mean holding a second copy of a
     * customer's income and phone number for no purpose beyond debugging.
     *
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }

    /**
     * A stable key so a retry is recognisably the same submission.
     *
     * Derived from the application and the attempt, never random: a fresh
     * UUID on every retry would defeat exactly the provider-side idempotency
     * it exists to engage.
     */
    public function idempotencyKey(int $attempt): string
    {
        return $this->fynnEdgeReference.'-'.$attempt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }
}
