<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has happened to a support case, in order.
 *
 * Append-only, following the same pattern as the application timeline from
 * Module 9 onwards. Nothing updates a row here and nothing deletes one: a
 * history a customer can watch change is not a history.
 *
 * Kept apart from messages on purpose. "Request acknowledged" is something
 * that happened; "here is the account number you asked for" is something
 * somebody said. Merging them produces a timeline where a status change
 * looks like a reply, which is exactly the impression Module 30 must not
 * give while there is nobody to reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_case_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_case_id')
                ->constrained()
                ->cascadeOnDelete();

            // What the customer reads on the timeline.
            $table->string('label', 80);

            // The transition, when this event was one.
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();

            // 'customer', 'advisor' or 'fynnedge'.
            $table->string('source', 20);

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['support_case_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_events');
    }
};
