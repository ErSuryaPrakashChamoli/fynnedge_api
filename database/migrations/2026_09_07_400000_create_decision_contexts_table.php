<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A financial decision the customer explored.
 *
 * Two kinds are written here — a simulation and a comparison — because
 * those are the two things a customer does that reach the server as an
 * action rather than as a page view. Opening a product, opening FynnTrust
 * and opening FynnMirror are not recorded, and FynnTrust and FynnMirror do
 * not even have an endpoint: recording them would mean inventing one, to
 * build a history of what somebody looked at. That is tracking, and it is
 * not what this table is for.
 *
 * Applications are not written here either. `loan_applications` already
 * holds an immutable snapshot of exactly what the customer was shown — the
 * amount, tenure, rate, EMI, fees and the catalogue version that produced
 * them — so decision history reads it rather than keeping a second copy
 * that could disagree with the first.
 *
 * There is deliberately no `application_id`. FynnEdge cannot know that a
 * simulation led to an application without inferring it from "same product,
 * later", and a column that could only ever be filled by a guess is a
 * column that will be.
 *
 * The table starts EMPTY and nothing is ever reconstructed into it. A
 * customer who explored nothing before this shipped has no decision
 * history, and the honest answer to "what did I look at?" is that FynnEdge
 * does not know.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_contexts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type', 40);

            /*
             * Which product, and which version of it.
             *
             * The version is what gives a historical decision its integrity:
             * a rate that changes in the catalogue tomorrow does not change
             * what this customer was shown today, because the version that
             * showed it is immutable and named here.
             */
            $table->string('product_code', 60)->nullable();
            $table->unsignedBigInteger('product_version_id')->nullable();

            /*
             * What was shown, as figures.
             *
             * Constrained by DecisionSnapshot's allowlist — amounts, rates,
             * ratios and the customer's own state at the time. Never an
             * identity value, a document, a provider payload or a token.
             */
            $table->json('snapshot');

            /*
             * The same exploration, recorded once.
             *
             * Derived from the type, the inputs, the customer's state and
             * the day — so fifty adjustments of a slider that land on the
             * same figures are one exploration, and a genuinely different
             * one is its own record.
             */
            $table->string('idempotency_key', 190);

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);

            // The history's only query.
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_contexts');
    }
};
