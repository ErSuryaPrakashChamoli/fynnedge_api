<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times a failed event has been re-attempted.
 *
 * Module 28 recorded events it could not apply so an operator could find
 * them, and nothing ever looked at them again. The commonest reason is a
 * genuine race — a provider calling back before FynnEdge has stored the
 * reference the callback quotes — and that resolves itself if anything
 * tries a second time.
 *
 * These two columns are what make retrying accountable rather than
 * invisible: an operator can tell "tried once, ten minutes ago" from
 * "tried four hundred times since March", which is the difference between
 * a race worth waiting out and something permanently broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_events', function (Blueprint $table) {
            $table->unsignedSmallInteger('retry_count')
                ->default(0)
                ->after('outcome_reason');

            $table->timestamp('last_retried_at')
                ->nullable()
                ->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('provider_events', function (Blueprint $table) {
            $table->dropColumn(['retry_count', 'last_retried_at']);
        });
    }
};
