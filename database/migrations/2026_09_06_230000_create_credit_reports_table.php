<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What FynnEdge keeps from a credit report.
 *
 * Four values and some bookkeeping. A bureau report carries a name, a date
 * of birth, a PAN, addresses, an employer, every credit account with its
 * balance and payment history, and every enquiry anyone has made — and
 * FynnEdge needs none of it. The only question the product asks is whether a
 * documented minimum score is met.
 *
 * So the surplus is read by the normalizer and dropped. It is not stored,
 * not logged and not passed on. There is deliberately no raw-payload column:
 * keeping one would mean holding a customer's entire credit history for no
 * stated purpose, and "we might need it later" is not a purpose.
 *
 * RETENTION IS NOT SETTLED. Rows are deleted when the customer withdraws
 * bureau consent, which is the behaviour Module 23 chose and tested. How
 * long a report may be kept while consent stands, and what a bureau contract
 * will require, are open questions. POLICY REQUIRED BEFORE ANY PRODUCTION
 * BUREAU LAUNCH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_reports', function (Blueprint $table) {
            $table->id();

            // Ownership comes from the token, always. Nothing in a request
            // payload decides whose report this is.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('bureau', 30);

            // A score is meaningless without the model it was measured on
            // and the scale that model uses, so the three are never
            // separated.
            $table->unsignedSmallInteger('score');
            $table->string('score_model', 40);
            $table->unsignedSmallInteger('score_min');
            $table->unsignedSmallInteger('score_max');

            // When the bureau answered — not when FynnEdge displayed it.
            $table->timestamp('obtained_at');

            /*
             * The bureau's own reference for this enquiry.
             *
             * Kept only so a support question about a specific request can
             * be taken back to the bureau. Never returned to the app, and
             * never used as any kind of customer-facing identity.
             */
            $table->string('bureau_reference', 120)->nullable();

            $table->timestamps();

            // The customer's most recent report, which is the only one any
            // read asks for.
            $table->index(['user_id', 'obtained_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_reports');
    }
};
