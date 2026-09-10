<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something that happened in a customer's financial life.
 *
 *     DOMAIN EVENT  ≠  FINANCIAL MOMENT  ≠  NOTIFICATION
 *
 * A domain event is FynnEdge's record that something occurred: a provider
 * event arrived, a row changed, a job ran. A moment is the handful of those
 * a customer would recognise as part of their own story — reaching half a
 * goal, a score becoming available, an application being decided. A
 * notification is the still smaller set worth interrupting them about, and
 * Module 29 decides that, not this table.
 *
 * Moments are HISTORICAL FACTS and are never rewritten. A moment saying a
 * score improved from 64 to 71 stays true even after the score falls to 55:
 * it records what happened, not what is currently the case. Nothing in the
 * codebase updates a row here, and nothing regenerates them from today's
 * figures.
 *
 * The unique index on (user_id, idempotency_key) is what makes that hold
 * under load. Reaching 50% of a goal is one moment however many times the
 * home screen is refreshed, and the database decides that rather than a
 * read-then-write.
 *
 * `title` and `description` are written at the time by MomentCopy, from
 * figures that were true then. That is deliberate: deriving the sentence
 * later would mean deriving it from today's state, which is exactly the
 * rewriting this table exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_moments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type', 40);
            $table->string('importance', 20);

            $table->string('title', 120);
            $table->string('description', 400);

            /*
             * What it was about, safely.
             *
             * An allowlisted type and the reference the customer already
             * sees — never a raw id, and never anything out of the record.
             */
            $table->string('context_type', 40)->nullable();
            $table->string('context_reference', 40)->nullable();

            /*
             * Why this moment exists, for FynnEdge rather than the customer.
             *
             * The rule that fired and the values it fired on, so "why did
             * this appear?" has an answer that is not a shrug. Never shown
             * to the customer and never carrying anything sensitive.
             */
            $table->string('rule', 60);
            $table->string('evidence', 190)->nullable();

            $table->string('idempotency_key', 190);

            $table->timestamp('occurred_at');
            $table->timestamps();

            // The defence against a refreshed screen celebrating twice.
            $table->unique(['user_id', 'idempotency_key']);

            // The timeline's only query.
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_moments');
    }
};
