<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to an application.
 *
 * [source] says who is speaking: FynnEdge about its own actions, a simulated
 * gateway about a submission that went nowhere, or a provider about something
 * they actually did. The app renders the three differently.
 */
class ApplicationEvent extends Model
{
    use HasFactory;

    public const SOURCE_FYNNEDGE = 'fynnedge';

    public const SOURCE_SIMULATED = 'simulated';

    public const SOURCE_PROVIDER = 'provider';

    protected $fillable = ['label', 'detail', 'source', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function isSimulated(): bool
    {
        return $this->source === self::SOURCE_SIMULATED;
    }
}
