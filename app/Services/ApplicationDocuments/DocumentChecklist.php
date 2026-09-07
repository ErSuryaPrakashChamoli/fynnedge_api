<?php

namespace App\Services\ApplicationDocuments;

use App\Models\LoanApplication;

/**
 * Every requirement for one application, and whether it is ready.
 *
 * The readiness rule lives here and only here. A controller that counted for
 * itself, or a screen that decided from ticks on a list, would eventually
 * disagree with this — and a customer shown two answers to "am I ready?"
 * stops believing either.
 */
final class DocumentChecklist
{
    /** @param list<ChecklistItem> $items */
    public function __construct(
        public readonly LoanApplication $application,
        public readonly array $items,
    ) {}

    /**
     * The one readiness rule.
     *
     * Every *required* requirement in the Ready state. Optional ones are
     * offered and never counted: an application whose required documents
     * are all confirmed is ready whatever the optional ones say.
     */
    public function isReady(): bool
    {
        foreach ($this->items as $item) {
            if ($item->blocks()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ChecklistItem> */
    public function required(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (ChecklistItem $i) => $i->requirement->required,
        ));
    }

    /** @return list<ChecklistItem> */
    public function outstanding(): array
    {
        return array_values(array_filter(
            $this->items,
            fn (ChecklistItem $i) => $i->blocks(),
        ));
    }

    private function readyCount(): int
    {
        return count(array_filter(
            $this->required(),
            fn (ChecklistItem $i) => $i->isSatisfied(),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            // The customer's own reference, not a database key.
            'application_reference' => $this->application->reference,

            /*
             * The server's answer, and the only authoritative one.
             *
             * A client may mirror the rule to render a progress line; it
             * must not decide from its own count.
             */
            'ready' => $this->isReady(),

            'required_count' => count($this->required()),
            'ready_count' => $this->readyCount(),
            'outstanding_count' => count($this->outstanding()),

            'items' => array_map(
                fn (ChecklistItem $i) => $i->toArray(),
                $this->items,
            ),

            /*
             * What being ready means, said plainly, because the word invites
             * exactly the wrong inference.
             */
            'summary' => $this->isReady()
                ? 'Your documents are ready for this application. Nothing '
                    .'has been sent anywhere yet.'
                : 'Some documents still need your attention before this '
                    .'application is ready.',

            'disclaimer' => 'Choosing and confirming documents prepares your '
                .'application inside FynnEdge. It does not send them to any '
                .'lender or provider, and no one outside FynnEdge has seen '
                .'them.',
        ];
    }
}
