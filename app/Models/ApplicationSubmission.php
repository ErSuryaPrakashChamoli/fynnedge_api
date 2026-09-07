<?php

namespace App\Models;

use App\Domain\Lender\SubmissionOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded attempt to send an application to a provider.
 */
class ApplicationSubmission extends Model
{
    protected $fillable = [
        'loan_application_id', 'user_id', 'attempt', 'provider',
        'is_simulated', 'outcome', 'provider_reference', 'failure_reason',
        'rejection_reason', 'product_id', 'provider_product_code',
        'fields_sent', 'idempotency_key', 'attempted_at', 'acknowledged_at',
    ];

    /**
     * The idempotency key never leaves the server. The provider reference is
     * shown deliberately and only where it means something, so it is not
     * hidden — but it is never used as an identity or a secret.
     */
    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'is_simulated' => 'boolean',
            'attempt' => 'integer',
            'fields_sent' => 'array',
            'attempted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LoanApplication::class, 'loan_application_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outcome(): SubmissionOutcome
    {
        return SubmissionOutcome::from($this->outcome);
    }

    /**
     * Whether an external party demonstrably holds this application.
     *
     * Both conditions, always. A simulated attempt cannot reach provider
     * receipt by construction, and this guards the case anyway.
     */
    public function providerHasIt(): bool
    {
        return $this->outcome()->providerHasIt() && ! $this->is_simulated;
    }
}
