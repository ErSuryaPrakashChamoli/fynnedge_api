<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to send one application to an external provider.
 *
 * A separate table rather than columns on the application, because attempts
 * are a sequence and an earlier one may have reached an uncertain external
 * state. Overwriting attempt 1 with attempt 2 would lose the fact that a
 * provider might be holding a copy from the first.
 *
 * What is NOT here: the outbound payload. FynnEdge records which fields were
 * sent, never their values — keeping a copy would mean holding a second copy
 * of a customer's income and phone number for the sake of debugging. Nor is
 * the provider's raw response stored; only the canonical outcome.
 *
 * RETENTION IS NOT SETTLED. Rows go with the application. How long a
 * submission record should outlive one, and what a provider contract will
 * require, are open. POLICY REQUIRED BEFORE ANY PRODUCTION LENDER LAUNCH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_application_id')
                ->constrained()
                ->cascadeOnDelete();

            // Recorded so a submission can be found by customer without
            // joining through the application.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Which try this was.
             *
             * Attempts are kept distinct and never renumbered, so a later
             * one cannot obscure an earlier attempt whose fate is unknown.
             */
            $table->unsignedSmallInteger('attempt');

            $table->string('provider', 40);

            /*
             * True when nothing left FynnEdge.
             *
             * Written from the provider adapter, never from a request, and
             * carried unchanged all the way to the app.
             */
            $table->boolean('is_simulated')->default(false);

            // The canonical outcome. Never a provider's own code, and there
            // is deliberately no value here meaning "approved".
            $table->string('outcome', 30);

            /*
             * The provider's own reference, when it gave one.
             *
             * Operational metadata: not a secret, not a token, and never a
             * replacement for the FynnEdge reference the customer sees.
             */
            $table->string('provider_reference', 120)->nullable();

            // Canonical, never a vendor body.
            $table->string('failure_reason', 40)->nullable();
            $table->string('rejection_reason', 60)->nullable();

            /*
             * Which product identity was actually submitted.
             *
             * Both sides recorded: FynnEdge's own product id and the code
             * the provider was given. A provider calling a product PL-001
             * while FynnEdge calls it something else is normal, and an
             * application must be answerable in both vocabularies.
             */
            $table->string('product_id', 60);
            $table->string('provider_product_code', 60)->nullable();

            /*
             * Which fields left, by name only.
             *
             * The answer to "what did you send about me?" without keeping a
             * second copy of the values.
             */
            $table->json('fields_sent')->nullable();

            // The key the provider was given, so a retry of this attempt is
            // recognisably the same submission to a provider that supports
            // idempotency.
            $table->string('idempotency_key', 120)->nullable();

            $table->timestamp('attempted_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['loan_application_id', 'attempt']);
            $table->index(['user_id', 'outcome']);
            $table->unique(['loan_application_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_submissions');
    }
};
