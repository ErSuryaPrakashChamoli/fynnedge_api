<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One verification attempt against one document.
 *
 * What is NOT here is the point of the table's shape. There is no column for
 * a PAN, an Aadhaar number, a date of birth, an address, a parent's name, a
 * photograph, or the provider's raw response — the fixture returns every one
 * of those and the normalizer drops them all. Each refusal is written down
 * with its reason in App\Domain\Kyc\IdentityFieldPurpose.
 *
 * The two findings are stored separately and never merged. A document can
 * pass a provider's own checks while the identity on it does not match the
 * customer, and one boolean would lose exactly the thing the customer needs
 * to act on.
 *
 * RETENTION IS NOT SETTLED. Rows go when the document goes and when identity
 * verification consent is withdrawn — the behaviour Module 25 chose and
 * tested. How long a result may be kept while consent stands, and what a
 * provider contract will require, are open. POLICY REQUIRED BEFORE ANY
 * PRODUCTION KYC LAUNCH.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();

            // Ownership comes from the token. Both recorded so a
            // verification can be found by customer without joining through
            // a document that may since have been deleted.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 40);

            /*
             * True when a fictional provider produced this.
             *
             * Written from the adapter, never from a request. It travels
             * unchanged to the app so a synthetic result can never be
             * displayed as a real verification.
             */
            $table->boolean('is_sample')->default(false);

            $table->string('verification_type', 30);
            $table->string('status', 20);

            // The two findings, apart.
            $table->string('document_result', 20)->nullable();
            $table->string('identity_result', 20)->nullable();

            /*
             * A masked tail of the document number — never the number.
             *
             * Its only purpose is letting a customer tell which of their own
             * documents was checked. Nothing reads it as an identifier and
             * nothing matches on it.
             */
            $table->string('masked_reference', 40)->nullable();

            // The provider's own identifiers. Operational only — never
            // returned to a customer, never used as an identity, never a
            // secret.
            $table->string('provider_reference', 120)->nullable();
            $table->string('method_version', 60)->nullable();

            // Canonical, never the provider's own code.
            $table->string('failure_reason', 40)->nullable();

            /*
             * The bytes this result describes.
             *
             * A verification belongs to a file, not to a document row. If
             * the file behind a document ever differs from this, the result
             * described something else and is not shown as describing this.
             */
            $table->string('file_digest', 64);

            $table->timestamp('checked_at')->nullable();

            /*
             * When the result stops being current.
             *
             * Null, always, today. No provider contract and no written
             * policy defines an expiry, and inventing one would make a
             * verification look stale on a date nobody chose. Expired is
             * never a synonym for failed.
             */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['document_id', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};
