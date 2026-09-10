<?php

namespace App\Models;

use App\Domain\Consent\ConsentStatus;
use App\Domain\Consent\ConsentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One consent decision, permanently.
 *
 * The model refuses to be updated or deleted. Consent evidence that can be
 * edited is not evidence, and a customer's history must read the same in a
 * year as it does today — including the parts FynnEdge might prefer it did
 * not say.
 */
class Consent extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'version',
        'status',
        'granted_at',
        'withdrawn_at',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException(
                'Consent records are append-only: record a new decision '
                .'instead of editing an existing one.'
            );
        });

        static::deleting(function (): never {
            throw new RuntimeException(
                'Consent records are append-only and cannot be deleted.'
            );
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): ConsentType
    {
        return ConsentType::from($this->type);
    }

    public function status(): ConsentStatus
    {
        return ConsentStatus::from($this->status);
    }

    public function isGranted(): bool
    {
        return $this->status() === ConsentStatus::Granted;
    }

    /**
     * The most recent decision per type — the customer's position now.
     *
     * The inner table is aliased on purpose. Written as an unaliased
     * `from('consents')`, the inner name shadows the outer one, so
     * `consents.user_id` resolves to the subquery's own row and the
     * correlation collapses into `user_id = user_id` — true for everybody.
     * The subquery then returns the newest id per type across the whole
     * table, and a customer's own decision disappears from their position
     * the moment any other customer decides the same type later.
     *
     * It fails closed rather than open — a granted consent reads as absent,
     * never the reverse — but a customer being told they never agreed to
     * something they did agree to is its own kind of wrong, and with one
     * customer in the database it is invisible.
     */
    public function scopeLatestPerType(Builder $query): Builder
    {
        return $query->whereIn('id', function ($sub) {
            $sub->selectRaw('max(id)')
                ->from('consents as latest_consents')
                ->whereColumn('latest_consents.user_id', 'consents.user_id')
                ->groupBy('latest_consents.type');
        });
    }
}
