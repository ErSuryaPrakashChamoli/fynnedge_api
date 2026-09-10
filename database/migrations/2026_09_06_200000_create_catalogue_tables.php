<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The product catalogue, persisted.
 *
 * Module 20 established the domain: a provider offers products, a product
 * has an identity that outlives its terms, and its terms live in versions
 * with effective dates. This gives that domain somewhere to live so it can
 * be administered rather than edited in a PHP array.
 *
 * Two things are load-bearing here:
 *
 *  - Identity is (provider, product_code), enforced by a unique index. It
 *    never changes when a rate does, which is what lets an application made
 *    last year keep pointing at the product it was made against.
 *
 *  - Published versions are immutable. There is no database mechanism that
 *    enforces that portably, so it is enforced in the domain and asserted by
 *    tests; what the schema contributes is `published_at`, which makes the
 *    distinction a fact rather than an inference.
 *
 * Money is decimal, never float — the same rule as every other table here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();

            // Stable internal identity. Never shown to a customer, never
            // derived from the display name.
            $table->string('code', 40)->unique();

            // What the customer reads. Free to change without breaking a
            // single reference.
            $table->string('name', 120);

            $table->string('type', 20);

            // active | inactive. An inactive provider's products leave
            // discovery; nothing is deleted and no application changes.
            $table->string('status', 20)->default('active');

            // An invented organisation used to demonstrate the product. A
            // sample provider may not carry live pricing.
            $table->boolean('is_sample')->default(true);

            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('catalogue_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();

            // Stable within the provider.
            $table->string('product_code', 40);

            // The catalogue-wide identity a customer-facing response and an
            // application both record. Normally provider_code:product_code;
            // the six sample products keep the `prod_*` strings they have
            // always had, because every application ever made against them
            // records those.
            $table->string('public_id', 90)->unique();

            $table->string('display_name', 120);
            $table->string('category', 30);

            // draft | active | inactive.
            $table->string('status', 20)->default('draft');

            $table->timestamps();

            // One product code per provider. Two providers may both offer a
            // `term_loan` without colliding.
            $table->unique(['provider_id', 'product_code']);
            $table->index(['status', 'category']);
        });

        Schema::create('product_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogue_product_id')
                ->constrained()
                ->cascadeOnDelete();

            // Null while a draft. Set once, at publication, and then the row
            // is closed to change.
            $table->timestamp('published_at')->nullable();

            $table->date('effective_from');

            // Null means still in force.
            $table->date('effective_until')->nullable();

            // --- Pricing --------------------------------------------------
            $table->decimal('interest_rate', 6, 3);
            $table->decimal('processing_fee_percent', 6, 3);
            $table->decimal('processing_fee_cap', 14, 2)->nullable();
            $table->string('prepayment_charge', 160)->default('');

            // --- What it will lend ----------------------------------------
            $table->decimal('min_amount', 14, 2);
            $table->decimal('max_amount', 14, 2);
            $table->unsignedSmallInteger('min_tenure_months');
            $table->unsignedSmallInteger('max_tenure_months');

            // --- Eligibility the provider publishes ------------------------
            // Null is "not published", never "no requirement". FynnMatch
            // reports an unpublished rule as unchecked rather than passed.
            $table->decimal('min_monthly_income', 14, 2)->nullable();
            $table->unsignedTinyInteger('min_age')->nullable();
            $table->unsignedTinyInteger('max_age_at_maturity')->nullable();

            // --- FynnEdge's published evaluation ---------------------------
            $table->unsignedTinyInteger('fynn_trust');
            $table->json('trust_breakdown');

            // --- Copy. Explains; never decides. ---------------------------
            $table->string('lender_tag', 60)->default('');
            $table->json('suited_reasons');
            $table->json('considerations');
            $table->json('conditions');

            // --- Provenance ------------------------------------------------
            // sample | manual | provider_feed | provider_api.
            $table->string('source_type', 20);

            // What to check this record against. Null for sample data, which
            // references nothing because it describes nothing real.
            $table->string('source_reference', 190)->nullable();
            $table->timestamp('source_retrieved_at')->nullable();

            $table->timestamps();

            // The customer-facing read: this product's published versions,
            // newest effective first.
            // Named explicitly: the generated name would be 71 characters,
            // and MySQL caps an identifier at 64.
            $table->index(
                ['catalogue_product_id', 'published_at', 'effective_from'],
                'product_versions_product_published_effective_index',
            );
        });

        Schema::create('catalogue_audit_events', function (Blueprint $table) {
            $table->id();

            // Who did it. Null when it was an operator at a console rather
            // than an authenticated user — FynnEdge has no administrator
            // accounts, so this is honest rather than empty.
            $table->foreignId('actor_user_id')->nullable()->constrained('users');

            // How the change arrived, so a null actor is still explicable.
            $table->string('actor_label', 120);

            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 40);

            // Only the fields that changed, chosen deliberately. Never the
            // whole request.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            // Append-only: there is no updated_at because nothing is ever
            // updated.
            $table->timestamp('created_at');

            $table->index(['entity_type', 'entity_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_audit_events');
        Schema::dropIfExists('product_versions');
        Schema::dropIfExists('catalogue_products');
        Schema::dropIfExists('providers');
    }
};
