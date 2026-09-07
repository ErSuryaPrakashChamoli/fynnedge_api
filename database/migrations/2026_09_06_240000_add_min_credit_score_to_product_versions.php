<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's documented minimum credit score.
 *
 * Module 22 kept this in `unsupported_requirements`, because FynnEdge could
 * not evaluate it and a requirement folded into eligibility it never checked
 * would have made FynnMatch report a verdict it had no basis for.
 *
 * Module 23 built the capability, so the requirement moves to where the
 * eligibility FynnMatch does evaluate lives. Requirements FynnEdge still
 * cannot check — business vintage, banking history — stay where they were.
 *
 * Null means the product publishes no such requirement, and is never read as
 * "no minimum". A product that says nothing about credit needs no credit
 * check to match, exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->unsignedSmallInteger('min_credit_score')
                ->nullable()
                ->after('max_age_at_maturity');
        });
    }

    public function down(): void
    {
        Schema::table('product_versions', function (Blueprint $table) {
            $table->dropColumn('min_credit_score');
        });
    }
};
