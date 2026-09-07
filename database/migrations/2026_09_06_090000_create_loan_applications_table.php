<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The customer-facing reference, unique across the product.
            $table->string('reference', 24)->unique();

            // Which product, captured as it was when the customer chose it.
            // The catalogue can change; what they applied for cannot.
            $table->string('product_id', 60);
            $table->string('product_name');
            $table->string('lender');
            $table->string('product_category', 20);

            // Exactly what the customer asked for. Never defaulted, never
            // rounded, never substituted.
            $table->decimal('amount', 14, 2);
            $table->unsignedSmallInteger('tenure_months');
            $table->decimal('interest_rate', 6, 3);

            // The figures they were shown when they decided, so a later
            // rate change cannot rewrite the offer they acted on.
            $table->decimal('emi', 14, 2);
            $table->decimal('total_interest', 14, 2);
            $table->decimal('total_payable', 14, 2);
            $table->decimal('processing_fee', 14, 2);
            $table->decimal('projected_emi_ratio', 6, 2)->nullable();
            $table->string('match_category', 20);

            $table->string('status', 20)->default('started');

            // The acknowledgement made at submission, with what was agreed to.
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('acknowledgement_version', 10)->nullable();

            // Which gateway took it, and whether anything actually left.
            $table->string('gateway', 30)->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->string('provider_reference')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Set by the client so a repeated create is recognised as the
            // same operation rather than a second application.
            $table->string('idempotency_key', 64)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            // Deterministic listing: newest first, ties broken by id.
            $table->index(['user_id', 'created_at', 'id']);
            $table->unique(['user_id', 'idempotency_key']);
        });

        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_application_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('label');
            $table->text('detail')->nullable();

            // 'fynnedge' for what we did, 'simulated' for a mock gateway,
            // 'provider' only for something a provider actually told us.
            $table->string('source', 20)->default('fynnedge');

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['loan_application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('loan_applications');
    }
};
