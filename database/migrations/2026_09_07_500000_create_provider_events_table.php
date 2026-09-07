<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One event a provider delivered.
 *
 * The row exists so nothing is silently discarded: every authenticated
 * delivery gets one, whether it moved anything or not, so an operator can
 * always answer "what happened to the event the provider says it sent?".
 *
 * WHAT IS NOT HERE, and why it is not:
 *
 * There is no raw payload column. A provider callback about a loan
 * application arrives carrying whatever that provider chooses to send —
 * an applicant's name, their income, a PAN, a decision rationale — and
 * storing the body wholesale would recreate, in an operational table, every
 * copy of customer data Modules 23 to 27 took care not to make. Nothing
 * needs it: the canonical fields carry everything reconciliation uses, and
 * a payload hash is enough to tell two deliveries apart.
 *
 * There is no customer id, and no application id supplied by the provider.
 * Ownership is resolved from `provider_reference` through the submission
 * that owns it, so a delivery cannot name whose application it affects.
 *
 * RETENTION IS NOT SETTLED. These rows live with the application. How long
 * an operational event record should be kept is the project's standing
 * retention question and is not answered here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider_code', 40);

            /*
             * The idempotency key.
             *
             * The provider's own event id where the contract supplies one,
             * otherwise a deterministic fingerprint of the event's
             * identifying facts. Unique, so a redelivery collides here and
             * is answered from the existing row rather than applied twice.
             *
             * Never a timestamp alone: two genuinely different events can
             * share a moment, and one of them would be lost.
             */
            $table->string('idempotency_key', 200)->unique();

            $table->string('event_type', 40);

            // How the event finds its application. Not a customer id.
            $table->string('provider_reference', 120);

            /*
             * True when the simulated source produced this.
             *
             * Written from the authenticator, never from the body, and
             * carried into the customer's timeline so a simulated event is
             * never rendered as a real provider's word.
             */
            $table->boolean('is_simulated')->default(false);

            // Enough to tell two deliveries apart without keeping either.
            $table->string('payload_hash', 64)->nullable();

            $table->string('processing_status', 20);

            // Canonical, never a provider's own code or free text.
            $table->string('reason_code', 60)->nullable();
            $table->string('outcome_reason', 60)->nullable();

            /*
             * What it resolved to, once it did.
             *
             * Nullable because an event that could not be matched to a
             * submission is still recorded — an unresolvable event is
             * exactly the kind an operator needs to find.
             */
            $table->foreignId('loan_application_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignId('application_submission_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['provider_code', 'processing_status']);
            $table->index(['provider_reference', 'occurred_at']);
            $table->index(['loan_application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
