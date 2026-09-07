<?php

namespace App\Models;

use App\Domain\ProviderEvents\CanonicalProviderEvent;
use App\Domain\ProviderEvents\EventProcessingStatus;
use App\Domain\ProviderEvents\ProviderEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded provider delivery.
 *
 * Operational only. Nothing customer-facing reads this table — the customer
 * sees the application's own timeline, which carries a sentence rather than
 * an event record.
 */
class ProviderEvent extends Model
{
    protected $fillable = [
        'provider_code', 'idempotency_key', 'event_type', 'provider_reference',
        'is_simulated', 'payload_hash', 'processing_status', 'reason_code',
        'outcome_reason', 'loan_application_id', 'application_submission_id',
        'occurred_at', 'received_at', 'processed_at',
        'retry_count', 'last_retried_at',
    ];

    /**
     * The idempotency key and payload hash never leave the server. Neither
     * is a secret, and neither is any caller's business.
     */
    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return [
            'is_simulated' => 'boolean',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'last_retried_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(
            ApplicationSubmission::class,
            'application_submission_id',
        );
    }

    public function processingStatus(): EventProcessingStatus
    {
        return EventProcessingStatus::from($this->processing_status);
    }

    public function type(): ProviderEventType
    {
        return ProviderEventType::from($this->event_type);
    }

    /**
     * Whether re-attempting this could plausibly change anything.
     *
     * Only failures. An ignored event was understood and deliberately not
     * applied; a processed one is done; a rejected one never authenticated.
     * Retrying any of those would be asking the same question again and
     * getting the same answer.
     */
    public function isRetryable(): bool
    {
        return $this->processingStatus() === EventProcessingStatus::Failed;
    }

    /**
     * The canonical event, rebuilt from what was stored.
     *
     * Possible only because the canonical fields were persisted and the raw
     * payload was not — the shape chosen in Module 28 to avoid keeping a
     * copy of a provider's body turns out to be exactly what a retry needs.
     */
    public function toCanonical(): CanonicalProviderEvent
    {
        return new CanonicalProviderEvent(
            providerEventId: null,
            providerCode: $this->provider_code,
            type: $this->type(),
            providerReference: $this->provider_reference,
            occurredAt: $this->occurred_at,
            isSimulated: (bool) $this->is_simulated,
            reasonCode: $this->reason_code,
        );
    }
}
