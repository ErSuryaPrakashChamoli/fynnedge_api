<?php

namespace App\Domain\ApplicationDocuments;

use App\Domain\Vault\DocumentType;

/**
 * One document an application asks for.
 *
 * A requirement is not a document. It is a slot with a name, a vault type it
 * can be filled from, and whether the application can proceed without it.
 * What fills the slot is a document the customer already owns, referenced
 * and never copied.
 */
final class DocumentRequirement
{
    public function __construct(
        /** Stable identity. The only thing a client sends to name a slot. */
        public readonly string $code,

        public readonly string $title,
        public readonly string $description,

        /** Which vault type can fill it. */
        public readonly DocumentType $documentType,

        /**
         * Whether the application waits for it.
         *
         * Optional requirements are offered and never block: an application
         * with every required slot ready is ready, whatever the optional
         * ones say.
         */
        public readonly bool $required = true,

        /**
         * Whether more than one document belongs here.
         *
         * False everywhere today. Nothing in the current catalogue asks for
         * three months of payslips, and building aggregation for a rule no
         * product states would be inventing the requirement in order to
         * satisfy it. The field exists so a product that does ask can say
         * so; the checklist reports it, and one slot holds one document
         * until something needs otherwise.
         */
        public readonly bool $multiple = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,

            // Which vault type fills it, so the picker can offer the right
            // documents rather than everything the customer owns.
            'document_type' => $this->documentType->value,
            'document_type_label' => $this->documentType->label(),

            'required' => $this->required,
            'multiple' => $this->multiple,
        ];
    }
}
