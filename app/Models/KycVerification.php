<?php

namespace App\Models;

use App\Domain\Kyc\CheckOutcome;
use App\Domain\Kyc\VerificationStatus;
use App\Domain\Kyc\VerificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recorded verification attempt.
 */
class KycVerification extends Model
{
    protected $fillable = [
        'user_id', 'document_id', 'provider', 'is_sample', 'verification_type',
        'status', 'document_result', 'identity_result', 'masked_reference',
        'provider_reference', 'method_version', 'failure_reason',
        'file_digest', 'checked_at', 'expires_at',
    ];

    /**
     * The provider's own identifiers never leave the server. Hiding them
     * means an accidental toArray() anywhere cannot publish them.
     */
    protected $hidden = ['provider_reference', 'method_version', 'file_digest'];

    protected function casts(): array
    {
        return [
            'is_sample' => 'boolean',
            'checked_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function matches(): HasMany
    {
        return $this->hasMany(KycFieldMatch::class);
    }

    public function status(): VerificationStatus
    {
        return VerificationStatus::from($this->status);
    }

    public function type(): VerificationType
    {
        return VerificationType::from($this->verification_type);
    }

    public function documentOutcome(): ?CheckOutcome
    {
        return $this->document_result === null
            ? null
            : CheckOutcome::from($this->document_result);
    }

    public function identityOutcome(): ?CheckOutcome
    {
        return $this->identity_result === null
            ? null
            : CheckOutcome::from($this->identity_result);
    }

    /** Whether another provider call would be wasted right now. */
    public function isInFlight(): bool
    {
        return $this->status() === VerificationStatus::Processing;
    }
}
