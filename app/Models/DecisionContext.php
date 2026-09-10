<?php

namespace App\Models;

use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A financial decision the customer explored.
 *
 * Written once and never updated. What was shown at the time stays what was
 * shown at the time — a rate that changes tomorrow does not change what this
 * customer saw today.
 */
class DecisionContext extends Model
{
    protected $fillable = [
        'type',
        'product_code',
        'product_version_id',
        'snapshot',
        'idempotency_key',
        'occurred_at',
    ];

    /**
     * FynnEdge's own bookkeeping. The key says which exploration produced
     * this record and describes the system rather than the customer.
     */
    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): DecisionType
    {
        return DecisionType::from($this->type);
    }

    /**
     * What was shown, read back through the same allowlist that wrote it.
     *
     * Re-checked on read rather than trusted: a row edited by anything that
     * bypassed DecisionSnapshot would otherwise reach a customer's screen.
     */
    public function snapshot(): DecisionSnapshot
    {
        return DecisionSnapshot::of($this->snapshot ?? []);
    }
}
