<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpChallenge extends Model
{
    protected $fillable = [
        'reference',
        'mobile',
        'code_hash',
        'expires_at',
        'attempts',
        'consumed_at',
        'request_ip',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= config('fynnedge.otp.max_attempts');
    }

    public function isUsable(): bool
    {
        return ! $this->isExpired()
            && ! $this->isConsumed()
            && ! $this->isExhausted();
    }
}
