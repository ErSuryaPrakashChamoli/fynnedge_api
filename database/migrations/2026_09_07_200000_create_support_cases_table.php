<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer asking FynnEdge for help.
 *
 * The row holds what they asked and what has been done about it. It does
 * not hold a copy of whatever they were looking at: `context_type` and
 * `context_id` point at a record the customer owns, plus the two or three
 * safe fields a support screen needs to say which one — a reference, a
 * status sentence, a product name.
 *
 * NO ADVISOR SYSTEM, SUPPORT CRM OR TICKETING PROVIDER IS CONNECTED. This
 * is FynnEdge's own store, and every case in it will sit at `open` until a
 * person looks. That is the honest behaviour: a status that advanced on a
 * timer would be describing work nobody did.
 *
 * `subject` and `description` are the customer's own words about their own
 * money. They are treated as sensitive throughout: never logged, never sent
 * to FynnAI, never put in a notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * What the customer quotes when they talk to a person.
             *
             * Unique, and that uniqueness is the allocation mechanism
             * rather than a nicety — see CaseReference, which retries on
             * collision instead of reading the last one and adding one.
             */
            $table->string('reference', 24)->unique();

            $table->string('category', 40);
            $table->string('status', 30);
            $table->string('priority', 20);

            $table->string('subject', 160);
            $table->text('description');

            // An allowlisted type, never a model class name.
            $table->string('context_type', 40)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();

            /*
             * Safe fields only, snapshotted at creation.
             *
             * Kept rather than looked up live so a case still reads
             * correctly after the application moves on — and so support
             * can see what the customer was looking at when they asked.
             */
            $table->string('context_reference', 40)->nullable();
            $table->string('context_status_label', 120)->nullable();
            $table->string('context_product_name', 120)->nullable();

            /*
             * Duplicate protection, the same shape as Module 29's.
             *
             * A customer tapping "Get help" five times has asked once. The
             * unique index is what decides that, not a read-then-write.
             */
            $table->string('idempotency_key', 190)->nullable();

            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_advisor_update_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Why it ended, so `closed` does not have to mean three things.
            $table->string('closed_reason', 40)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);

            // The support screen's only list query.
            $table->index(['user_id', 'id']);

            // What an operator asks: is anything stuck?
            $table->index(['status', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_cases');
    }
};
