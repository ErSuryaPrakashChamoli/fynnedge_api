<?php

namespace App\Models;

use App\Domain\Scan\ScanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recorded attempt to read a document.
 */
class DocumentScan extends Model
{
    protected $fillable = [
        'user_id', 'document_id', 'provider', 'is_sample', 'status',
        'family', 'schema_version', 'file_digest', 'provider_reference',
        'model_version', 'failure_reason', 'classified_as',
        'document_period', 'started_at', 'completed_at',
    ];

    /**
     * The provider's own identifiers never leave the server. Hiding them
     * means an accidental toArray() anywhere cannot publish them.
     */
    protected $hidden = ['provider_reference', 'model_version', 'file_digest'];

    protected function casts(): array
    {
        return [
            'is_sample' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(DocumentScanField::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DocumentScanReview::class);
    }

    public function status(): ScanStatus
    {
        return ScanStatus::from($this->status);
    }

    /** Whether another provider call would be wasted right now. */
    public function isInFlight(): bool
    {
        return in_array($this->status(), [
            ScanStatus::Queued,
            ScanStatus::Processing,
        ], true);
    }
}
