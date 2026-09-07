<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which catalogue version an application was created from.
 *
 * Module 9 snapshotted the terms — amount, rate, EMI, fees — which is what
 * keeps a repriced catalogue from rewriting history. But the snapshot could
 * not say *which* published version produced those terms, and once Modules
 * 20-22 made versions first-class that became a real gap: an application at
 * 11.5% could not be traced to the version that offered 11.5%.
 *
 * This adds the identity alongside the snapshot. It does not replace it, and
 * nothing resolves it to look up today's price — the snapshot remains the
 * only source of what the customer applied under.
 *
 * Null for applications created before this, and for the sample catalogue,
 * which has no persisted versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            // The catalogue product's stable public identity.
            $table->string('catalogue_product_code', 80)
                ->nullable()
                ->after('product_category');

            // The exact published version whose terms were snapshotted.
            $table->unsignedBigInteger('product_version_id')
                ->nullable()
                ->after('catalogue_product_code');

            // The day that version was in force from, so the record stays
            // readable even if the version row is later removed.
            $table->date('product_version_effective_from')
                ->nullable()
                ->after('product_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'catalogue_product_code',
                'product_version_id',
                'product_version_effective_from',
            ]);
        });
    }
};
