<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The provider's own identifier for a product.
 *
 * Kept apart from FynnEdge's `product_code` because they answer to different
 * owners. A provider names its products however it likes — `KF-PL-001`,
 * uppercase and hyphenated — and that string is what its next feed will use
 * to mean "the same product", so it has to be matched on exactly.
 *
 * FynnEdge's own code stays in FynnEdge's format. Adopting the provider's
 * string as our identity would put their naming conventions into our public
 * URLs and our application records, and leave us stuck with them the day
 * they reorganise.
 *
 * Null for products created by an administrator, which have no provider
 * reference because no provider supplied them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalogue_products', function (Blueprint $table) {
            $table->string('provider_product_code', 120)
                ->nullable()
                ->after('product_code');

            // How the next feed finds this product again.
            $table->unique(['provider_id', 'provider_product_code']);
        });
    }

    public function down(): void
    {
        Schema::table('catalogue_products', function (Blueprint $table) {
            $table->dropUnique(['provider_id', 'provider_product_code']);
            $table->dropColumn('provider_product_code');
        });
    }
};
