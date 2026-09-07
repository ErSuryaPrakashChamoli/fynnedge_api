<?php

namespace App\Models;

use App\Domain\Catalog\Provider as ProviderData;
use App\Domain\Catalog\ProviderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A provider as the catalogue stores it.
 *
 * The domain object App\Domain\Catalog\Provider is what everything else
 * uses; this is only how it is written down. Nothing outside the catalogue
 * services should reach for this class.
 */
class Provider extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = ['code', 'name', 'type', 'status', 'is_sample'];

    protected function casts(): array
    {
        return ['is_sample' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(CatalogueProduct::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** The domain object, which is what the rest of FynnEdge understands. */
    public function toData(): ProviderData
    {
        return new ProviderData(
            code: $this->code,
            name: $this->name,
            type: ProviderType::from($this->type),
            isSample: (bool) $this->is_sample,
        );
    }
}
