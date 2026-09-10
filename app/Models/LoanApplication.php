<?php

namespace App\Models;

use App\Domain\Application\ApplicationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoanApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'product_id',
        'product_name',
        'lender',
        'product_category',
        'amount',
        'tenure_months',
        'interest_rate',
        'emi',
        'total_interest',
        'total_payable',
        'processing_fee',
        'projected_emi_ratio',
        'match_category',
        'status',
        'idempotency_key',

        // Which catalogue version produced the snapshot above. Identity
        // only — nothing resolves it to look up a current price.
        'catalogue_product_code',
        'product_version_id',
        'product_version_effective_from',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interest_rate' => 'decimal:3',
            'emi' => 'decimal:2',
            'total_interest' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'processing_fee' => 'decimal:2',
            'projected_emi_ratio' => 'decimal:2',
            'tenure_months' => 'integer',
            'is_simulated' => 'boolean',
            'acknowledged_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The documents the customer has chosen for this application. */
    public function documents(): HasMany
    {
        return $this->hasMany(ApplicationDocument::class);
    }

    /** Every attempt to send this application to a provider. */
    public function submissions(): HasMany
    {
        return $this->hasMany(ApplicationSubmission::class)
            ->orderBy('attempt');
    }

    /**
     * The most recent attempt, whatever became of it.
     *
     * Distinct from the application's own status: this says what an external
     * provider did, and that one says what the customer did in FynnEdge.
     *
     * Answered from the loaded relation when there is one. Without that
     * check this issues a query every time it is called, which turns any
     * caller holding a collection into an N+1 — and several do: the
     * intelligence rules ask it of every application, twice over, and each
     * of those asks was a round trip even though the submissions had just
     * been eager-loaded beside them.
     */
    public function latestSubmission(): ?ApplicationSubmission
    {
        if ($this->relationLoaded('submissions')) {
            return $this->submissions->sortByDesc('attempt')->first();
        }

        return $this->submissions()->orderByDesc('attempt')->first();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApplicationEvent::class)->orderBy('occurred_at');
    }

    public function status(): ApplicationStatus
    {
        return ApplicationStatus::from($this->status);
    }

    /**
     * Records something that happened, saying who says so.
     *
     * `source` is not cosmetic: 'simulated' must never be mistaken for
     * 'provider' by anything downstream.
     */
    public function recordEvent(
        string $label,
        string $detail,
        string $source,
        ?CarbonInterface $at = null,
    ): ApplicationEvent {
        return $this->events()->create([
            'label' => $label,
            'detail' => $detail,
            'source' => $source,
            'occurred_at' => $at ?? now(),
        ]);
    }
}
