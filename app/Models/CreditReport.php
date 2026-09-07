<?php

namespace App\Models;

use App\Domain\Credit\CreditBureau;
use App\Domain\Credit\CreditProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credit report as FynnEdge keeps it: a score, its model, its scale, and
 * when it was obtained.
 *
 * `bureau_reference` is hidden. It exists so a support question can be taken
 * back to the bureau, and it has no business reaching the app.
 */
class CreditReport extends Model
{
    protected $fillable = [
        'user_id', 'bureau', 'score', 'score_model',
        'score_min', 'score_max', 'obtained_at', 'bureau_reference',
    ];

    /** Internal. Never serialised to a customer. */
    protected $hidden = ['bureau_reference'];

    protected function casts(): array
    {
        return ['obtained_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toProfile(): CreditProfile
    {
        return new CreditProfile(
            bureau: CreditBureau::from($this->bureau),
            score: (int) $this->score,
            scoreModel: $this->score_model,
            scoreMin: (int) $this->score_min,
            scoreMax: (int) $this->score_max,
            obtainedAt: $this->obtained_at,
        );
    }
}
