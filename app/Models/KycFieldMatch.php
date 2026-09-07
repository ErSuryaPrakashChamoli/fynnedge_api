<?php

namespace App\Models;

use App\Domain\Kyc\FieldMatch;
use App\Domain\Kyc\MatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field comparison, as stored.
 */
class KycFieldMatch extends Model
{
    protected $fillable = [
        'kyc_verification_id', 'field', 'label', 'status', 'reason',
        'profile_value', 'verified_value',
    ];

    public function verification(): BelongsTo
    {
        return $this->belongsTo(KycVerification::class, 'kyc_verification_id');
    }

    public function toMatch(): FieldMatch
    {
        return new FieldMatch(
            field: $this->field,
            label: $this->label,
            status: MatchStatus::from($this->status),
            reason: $this->reason,
            profileValue: $this->profile_value,
            verifiedValue: $this->verified_value,
        );
    }
}
