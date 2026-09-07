<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One value a scan believes it found.
 *
 * Immutable once written. A customer correcting a figure does not edit the
 * row — the correction is a separate review record, so what the document
 * appeared to say and what the customer says survive as two facts rather
 * than one overwriting the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scan_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_scan_id')->constrained()->cascadeOnDelete();

            $table->string('key', 40);
            $table->string('label', 60);

            // As read. Null is a real answer: the document did not show it.
            $table->string('value', 200)->nullable();
            $table->string('value_type', 12);

            /*
             * Money in paise, alongside the display value.
             *
             * An amount off a payslip is money and gets the same exact
             * integer representation as every other rupee in FynnEdge.
             * There is no float column here on purpose.
             */
            $table->bigInteger('amount_paise')->nullable();

            // FynnEdge's scale, not a provider's number and not a percentage.
            $table->string('confidence', 12);

            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 240)->nullable();

            // Where in the document, so a customer can check the reading
            // against their own copy. Enough to point at; not a copy of it.
            $table->unsignedSmallInteger('evidence_page')->nullable();
            $table->string('evidence_section', 80)->nullable();
            $table->string('evidence_snippet', 200)->nullable();
            $table->json('evidence_box')->nullable();

            $table->timestamps();

            $table->unique(['document_scan_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scan_fields');
    }
};
