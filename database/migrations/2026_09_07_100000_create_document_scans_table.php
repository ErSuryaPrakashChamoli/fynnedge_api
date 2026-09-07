<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One attempt to read one document.
 *
 * Module 12 deliberately had no table: nothing had ever been scanned, so a
 * row per document recording that nothing happened would have been storage
 * without information. A provider now exists behind the seam, so attempts
 * and their results are worth keeping.
 *
 * What is NOT kept: the provider's raw response, the document's text, the
 * page images, and every field the feature does not need. The provider hands
 * back a payslip's PAN, Aadhaar, address and bank account; the normalizer
 * drops all of it and nothing here has a column to receive it.
 *
 * RETENTION IS NOT SETTLED. Rows go when the document goes and when
 * processing consent is withdrawn — the behaviour Module 24 chose and
 * tested. How long an extraction may be kept while consent stands, and what
 * a provider contract will require, are open. POLICY REQUIRED BEFORE ANY
 * PRODUCTION DOCUMENT-PROCESSING LAUNCH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table) {
            $table->id();

            // Ownership comes from the token. Both are recorded so a scan
            // can be found by customer without joining through a document
            // that may since have been deleted.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 40);

            /*
             * True when a fictional provider produced this.
             *
             * Written from the adapter, never from a request. It travels
             * unchanged to the app so a synthetic reading can never be
             * displayed as a real one.
             */
            $table->boolean('is_sample')->default(false);

            $table->string('status', 20);
            $table->string('family', 20);
            $table->string('schema_version', 20);

            /*
             * The bytes this reading was taken from.
             *
             * An extraction belongs to a file, not to a document type. If
             * the bytes behind a document ever differ from this, the reading
             * is stale and is not shown as though it described them.
             */
            $table->string('file_digest', 64);

            // The provider's own identifiers. Operational only — never
            // returned to a customer, never used as an identity.
            $table->string('provider_reference', 120)->nullable();
            $table->string('model_version', 60)->nullable();

            // Canonical, never the provider's own code.
            $table->string('failure_reason', 40)->nullable();

            // What the provider thought this was, when it says. Kept apart
            // from the vault's type, which is what the customer said.
            $table->string('classified_as', 40)->nullable();

            // The period the document covers. Not when it was processed.
            $table->string('document_period', 20)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The latest attempt for a document, which is the only one any
            // read asks for.
            $table->index(['document_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
