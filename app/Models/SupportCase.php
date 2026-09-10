<?php

namespace App\Models;

use App\Domain\Support\ResolvedContext;
use App\Domain\Support\SupportCaseStatus;
use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportContextType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One customer asking FynnEdge for help.
 *
 * `status` and `priority` are absent from $fillable, and that is the point:
 * both are FynnEdge's to decide. A customer who could mass-assign either
 * could mark their own request resolved, or make every request urgent.
 * They move through SupportCaseService and a forceFill, never through a
 * request body.
 */
class SupportCase extends Model
{
    /**
     * Everything a customer legitimately supplies, and nothing else.
     *
     * Not user_id: ownership comes from the relation the row is created
     * through. Not status or priority: FynnEdge decides those.
     */
    protected $fillable = [
        'reference',
        'category',
        'subject',
        'description',
        'context_type',
        'context_id',
        'context_reference',
        'context_status_label',
        'context_product_name',
        'idempotency_key',
    ];

    /**
     * FynnEdge's own bookkeeping. The key says which button produced this
     * case; a customer has no use for it and it describes the system.
     */
    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'last_customer_message_at' => 'datetime',
            'last_advisor_update_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportCaseMessage::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportCaseEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function status(): SupportCaseStatus
    {
        return SupportCaseStatus::from($this->status);
    }

    public function category(): SupportCategory
    {
        return SupportCategory::from($this->category);
    }

    /**
     * The context as the customer sees it, rebuilt from the safe fields
     * snapshotted at creation. Nothing is looked up again: the record it
     * pointed at may have moved on, and the case is about what they were
     * looking at when they asked.
     */
    public function context(): ?ResolvedContext
    {
        $type = SupportContextType::tryFrom((string) $this->context_type);

        if ($type === null) {
            return null;
        }

        return new ResolvedContext(
            type: $type,
            id: $this->context_id === null ? null : (int) $this->context_id,
            reference: $this->context_reference,
            statusLabel: $this->context_status_label,
            productName: $this->context_product_name,
        );
    }

    /** Append one line to the timeline. Nothing here ever updates one. */
    public function recordEvent(
        string $label,
        string $source,
        ?SupportCaseStatus $from = null,
        ?SupportCaseStatus $to = null,
        ?Carbon $at = null,
    ): SupportCaseEvent {
        return $this->events()->create([
            'label' => $label,
            'source' => $source,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'occurred_at' => $at ?? now(),
        ]);
    }
}
