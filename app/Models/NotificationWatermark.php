<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The last value FynnEdge told a customer about, for values that move. */
class NotificationWatermark extends Model
{
    public const FYNN_SCORE_BAND = 'fynn_score_band';

    protected $fillable = ['key', 'value', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
