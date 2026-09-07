<?php

namespace App\Models;

use App\Domain\Money;
use App\Domain\Scan\ConfidenceLevel;
use App\Domain\Scan\ExtractedField;
use App\Domain\Scan\FieldEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One extracted value, as stored.
 *
 * Written once. Customer corrections live in document_scan_reviews, so this
 * row keeps saying what the document appeared to say.
 */
class DocumentScanField extends Model
{
    protected $fillable = [
        'document_scan_id', 'key', 'label', 'value', 'value_type',
        'amount_paise', 'confidence', 'needs_review', 'review_reason',
        'evidence_page', 'evidence_section', 'evidence_snippet', 'evidence_box',
    ];

    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'amount_paise' => 'integer',
            'evidence_page' => 'integer',
            'evidence_box' => 'array',
        ];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DocumentScan::class, 'document_scan_id');
    }

    public function amount(): ?Money
    {
        return $this->amount_paise === null
            ? null
            : Money::fromPaise($this->amount_paise);
    }

    public function toField(): ExtractedField
    {
        return new ExtractedField(
            key: $this->key,
            label: $this->label,
            value: $this->value,
            valueType: $this->value_type,
            confidence: ConfidenceLevel::from($this->confidence),
            evidence: FieldEvidence::fromArray([
                'page' => $this->evidence_page,
                'section' => $this->evidence_section,
                'snippet' => $this->evidence_snippet,
                'box' => $this->evidence_box,
            ]),
            reviewReason: $this->review_reason,
            paise: $this->amount_paise,
        );
    }
}
