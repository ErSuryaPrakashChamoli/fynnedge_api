<?php

namespace App\Models;

use App\Domain\Catalog\ProductStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product identity as the catalogue stores it.
 *
 * Carries nothing that can change while it stays the same product: no rate,
 * no fee, no eligibility. Those live in versions, which is what makes an
 * application made against this product still describe the terms it was
 * made under.
 */
class CatalogueProduct extends Model
{
    protected $fillable = [
        'provider_id', 'product_code', 'provider_product_code', 'public_id',
        'display_name', 'category', 'status',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductVersion::class);
    }

    public function publishedVersions(): HasMany
    {
        return $this->versions()->whereNotNull('published_at');
    }

    public function status(): ProductStatus
    {
        return ProductStatus::from($this->status);
    }

    /**
     * The identity a customer response and an application both record.
     *
     * Normally `provider_code:product_code`. The six sample products keep
     * the `prod_*` strings they were given before providers were modelled,
     * because every application ever made against them records those.
     */
    public static function composeId(string $providerCode, string $productCode): string
    {
        return $providerCode.':'.$productCode;
    }
}
