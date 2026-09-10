<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing that happened to a support case. Append-only. */
class SupportCaseEvent extends Model
{
    public const SOURCE_CUSTOMER = 'customer';

    public const SOURCE_ADVISOR = 'advisor';

    public const SOURCE_FYNNEDGE = 'fynnedge';

    protected $fillable = ['label', 'source', 'from_status', 'to_status', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }
}
