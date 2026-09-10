<?php

namespace App\Models;

use App\Domain\Money;
use App\Domain\Scan\ReviewDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The customer's answer about one extracted field.
 */
class DocumentScanReview extends Model
{
    protected $fillable = [
        'document_scan_id', 'user_id', 'field_key', 'decision',
        'extracted_value', 'customer_value', 'customer_amount_paise',
        'applied_to_profile', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'applied_to_profile' => 'boolean',
            'customer_amount_paise' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DocumentScan::class, 'document_scan_id');
    }

    /**
     * Who answered.
     *
     * The scan already knows its customer, so nothing in the API needs this
     * — it is here because the column and its foreign key exist, and a real
     * relation the model does not describe is the kind of gap the admin
     * panel discovers the hard way.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decision(): ReviewDecision
    {
        return ReviewDecision::from($this->decision);
    }

    public function customerAmount(): ?Money
    {
        return $this->customer_amount_paise === null
            ? null
            : Money::fromPaise($this->customer_amount_paise);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'field_key' => $this->field_key,
            'decision' => $this->decision,
            'decision_label' => $this->decision()->label(),

            // Both survive. What the document appeared to say and what the
            // customer says it is are two facts, not one correction.
            'extracted_value' => $this->extracted_value,
            'customer_value' => $this->customer_value,

            'applied_to_profile' => (bool) $this->applied_to_profile,
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
