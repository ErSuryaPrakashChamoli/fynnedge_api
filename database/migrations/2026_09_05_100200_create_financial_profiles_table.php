<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers every FynnEdge calculation depends on.
 *
 * Money is stored as decimal, never float: a rupee that drifts is a rupee the
 * customer will notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->decimal('monthly_income', 14, 2)->default(0);
            $table->decimal('monthly_expenses', 14, 2)->default(0);
            $table->decimal('existing_emi', 14, 2)->default(0);
            $table->decimal('savings', 14, 2)->default(0);

            // Rent, school fees, support payments — outgoings that are not
            // EMIs but still reduce what the customer can service.
            $table->decimal('other_obligations', 14, 2)->default(0);

            $table->string('employment_type', 30)->default('salaried');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_profiles');
    }
};
