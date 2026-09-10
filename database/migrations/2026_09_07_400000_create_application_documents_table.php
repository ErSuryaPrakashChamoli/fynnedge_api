<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which document a customer chose for one requirement on one application.
 *
 * One table, deliberately. A second `application_document_reviews` table was
 * considered and rejected: what the customer decided is a single current
 * fact per slot, and the history of how they got there already has a home in
 * `application_events`, which is append-only and is what the customer's own
 * timeline reads. Two audit systems for one act is one too many.
 *
 * What is NOT here is the point of the shape. There is no file, no copy of
 * one, no path, no storage key, no extracted text and no field lifted out of
 * a document. This table holds a reference to a row in `documents` and the
 * customer's decision about it — nothing that would make the vault's
 * contents exist in a second place.
 *
 * The digest is how a stale confirmation is caught: a customer confirms a
 * particular file, and if the bytes behind that document ever differ, the
 * confirmation described something else and does not carry over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_application_id')
                ->constrained()
                ->cascadeOnDelete();

            // Recorded so ownership can be checked without joining through
            // the application, and so a customer's choices survive a
            // document being removed.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Which slot this fills.
             *
             * A code from the application's own requirement set, validated
             * against it. Nothing a client sends creates a slot.
             */
            $table->string('requirement_code', 40);

            /*
             * The vault document, by reference.
             *
             * Nulled rather than cascaded when the document goes, so the
             * row survives to say "the document you chose is no longer
             * there" instead of vanishing and looking like the customer
             * never chose one.
             */
            $table->foreignId('document_id')
                ->nullable()
                ->constrained('documents')
                ->nullOnDelete();

            $table->string('status', 20);

            /*
             * The bytes the customer confirmed.
             *
             * If the file behind the document ever differs from this, the
             * confirmation described a different file and the slot returns
             * to needing review. Never used to fetch anything.
             */
            $table->string('file_digest', 64)->nullable();

            // When they decided, and what they decided. The actor is always
            // the owner; there is no column for who else it might have been.
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            /*
             * One active choice per slot.
             *
             * A new selection replaces the previous one rather than
             * accumulating, so there is never an ambiguous pair of current
             * documents for the same requirement.
             */
            // Named explicitly: the generated name would be 65 characters,
            // and MySQL caps an identifier at 64.
            $table->unique(
                ['loan_application_id', 'requirement_code'],
                'app_docs_application_requirement_unique',
            );
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_documents');
    }
};
