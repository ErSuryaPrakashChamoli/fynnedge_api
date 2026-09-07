<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer said about one extracted field.
 *
 * The whole point of Module 24 lives in this table. An extraction is
 * evidence; a review is the customer's decision about it. Keeping them apart
 * means FynnEdge can always answer both "what did the document appear to
 * say" and "what did the customer say it was" — and can never confuse the
 * two.
 *
 * `extracted_value` is copied here at review time so the decision remains
 * readable even if the scan is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scan_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_scan_id')->constrained()->cascadeOnDelete();

            // The actor is always the authenticated customer. Nothing in a
            // payload names who confirmed something.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('field_key', 40);

            // confirmed | edited | rejected
            $table->string('decision', 12);

            // What the document appeared to say, as it stood when reviewed.
            $table->string('extracted_value', 200)->nullable();

            // What the customer says it is. Null for a rejection: rejecting
            // is declining to supply a value, not supplying an empty one.
            $table->string('customer_value', 200)->nullable();
            $table->bigInteger('customer_amount_paise')->nullable();

            // Whether this decision was carried into the customer's profile,
            // which is a separate act the customer asks for explicitly.
            $table->boolean('applied_to_profile')->default(false);

            $table->timestamp('decided_at');
            $table->timestamps();

            // One standing decision per field per scan. A change of mind
            // replaces it rather than accumulating rows, and a double tap
            // writes the same thing twice with no second effect.
            $table->unique(['document_scan_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scan_reviews');
    }
};
