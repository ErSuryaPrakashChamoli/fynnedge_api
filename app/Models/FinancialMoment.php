<?php

namespace App\Models;

use App\Domain\Intelligence\FinancialMomentType;
use App\Domain\Intelligence\MomentImportance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened in a customer's financial life.
 *
 * A historical fact. Nothing in FynnEdge updates a row here — the model
 * carries no method that would, and the service that writes them only ever
 * inserts. A moment saying a score improved stays true after the score
 * falls, because it records what happened rather than what is the case.
 */
class FinancialMoment extends Model
{
    protected $fillable = [
        'type',
        'importance',
        'title',
        'description',
        'context_type',
        'context_reference',
        'rule',
        'evidence',
        'idempotency_key',
        'occurred_at',
    ];

    /**
     * FynnEdge's own bookkeeping. `rule` and `evidence` say why the moment
     * exists and `idempotency_key` says which trigger produced it; all
     * three describe the system rather than the customer.
     */
    protected $hidden = ['rule', 'evidence', 'idempotency_key'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): FinancialMomentType
    {
        return FinancialMomentType::from($this->type);
    }

    public function importance(): MomentImportance
    {
        return MomentImportance::from($this->importance);
    }
}
