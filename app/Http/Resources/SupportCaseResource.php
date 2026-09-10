<?php

namespace App\Http\Resources;

use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read SupportCase $resource
 */
class SupportCaseResource extends JsonResource
{
    public function __construct($resource, private readonly bool $withDetail = false)
    {
        parent::__construct($resource);
    }

    /** The detail view: messages and timeline included. */
    public static function detailed(SupportCase $case): self
    {
        return new self($case, true);
    }

    /**
     * What the customer is entitled to see about their own request.
     *
     * The reference is the identifier they read and quote. `id` is present
     * because the deep-link contract and every other route in the API is
     * keyed on it, so hiding it would mean inventing a second lookup — but
     * nothing else internal travels: no user_id, no idempotency key, no
     * author id, no context_id.
     */
    public function toArray(Request $request): array
    {
        $case = $this->resource;
        $status = $case->status();

        $base = [
            'id' => (string) $case->id,
            'reference' => $case->reference,

            'category' => $case->category,
            'category_label' => $case->category()->label(),

            'status' => $case->status,
            'status_label' => $status->label(),
            'status_description' => $status->description(),
            'is_open' => ! $status->isTerminal(),
            'accepts_messages' => $status->acceptsCustomerMessages(),

            // Shown so a customer knows FynnEdge treated their stuck
            // application differently. It is not something they set.
            'priority' => $case->priority,

            'subject' => $case->subject,

            'context' => $case->context()?->toArray(),

            'created_at' => $case->created_at?->toIso8601String(),
            'updated_at' => $case->updated_at?->toIso8601String(),
            'resolved_at' => $case->resolved_at?->toIso8601String(),
            'closed_at' => $case->closed_at?->toIso8601String(),
        ];

        if (! $this->withDetail) {
            return $base;
        }

        return [
            ...$base,

            // The description is the customer's own account of the problem.
            // Sent on the detail view only — a list of requests does not
            // need every word of every one.
            'description' => $case->description,

            'messages' => $case->messages->map(fn (SupportCaseMessage $m) => [
                'id' => (string) $m->id,

                // 'you' or 'fynnedge'. Never an advisor's identity, and
                // never an internal id.
                'author' => $m->isFromCustomer() ? 'you' : 'fynnedge',
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),

            'timeline' => $case->events->map(fn (SupportCaseEvent $e) => [
                'label' => $e->label,
                'source' => $e->source,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
