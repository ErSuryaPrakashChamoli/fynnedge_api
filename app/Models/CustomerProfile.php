<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerProfile extends Model
{
    protected $fillable = [
        'full_name',
        'age',
        'city',
        'email',
        'intent',
        'primary_goal',
        'onboarding_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isComplete(): bool
    {
        return filled($this->full_name) && filled($this->city);
    }
}
