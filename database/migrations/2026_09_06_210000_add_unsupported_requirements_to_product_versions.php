<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider requirements FynnEdge cannot evaluate.
 *
 * A provider may publish a minimum credit score or a business vintage.
 * FynnEdge holds neither, so it cannot say whether a customer meets them.
 *
 * They are kept because dropping them would leave a product looking fully
 * checked when it is not, and kept in their own column because folding them
 * into the eligibility FynnMatch reads would make it report a verdict on
 * something it never looked at. Neither pass nor fail: outside what FynnEdge
 * can currently see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->json('unsupported_requirements')->nullable()->after('conditions');
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn('unsupported_requirements');
        });
    }
};
