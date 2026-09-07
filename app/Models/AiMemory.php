<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing FynnAI remembers about a customer between conversations.
 *
 * Only keys on the memory policy allowlist, only while the customer's
 * ai_memory consent stands.
 */
class AiMemory extends Model
{
    use HasFactory;

    protected $table = 'ai_memories';

    protected $fillable = ['key', 'value', 'source'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
