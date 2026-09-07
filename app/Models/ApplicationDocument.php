<?php

namespace App\Models;

use App\Domain\ApplicationDocuments\RequirementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requirement slot, and the document the customer put in it.
 */
class ApplicationDocument extends Model
{
    protected $fillable = [
        'loan_application_id', 'user_id', 'requirement_code',
        'document_id', 'status', 'file_digest', 'reviewed_at',
    ];

    /**
     * The digest never leaves the server. It exists to detect a changed
     * file, not to be shown or matched on by a client.
     */
    protected $hidden = ['file_digest'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function status(): RequirementStatus
    {
        return RequirementStatus::from($this->status);
    }

    /**
     * Whether the document behind this choice is still there and usable.
     *
     * A soft-deleted document is gone as far as the customer is concerned,
     * so the relation deliberately does not include trashed rows.
     */
    public function hasUsableDocument(): bool
    {
        return $this->document !== null && $this->document->exists();
    }
}
