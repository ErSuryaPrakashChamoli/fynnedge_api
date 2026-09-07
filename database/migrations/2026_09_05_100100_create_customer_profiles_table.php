<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the customer is. Captured across onboarding screens 5 to 7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('full_name')->nullable();
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('city')->nullable();
            $table->string('email')->nullable();

            // Screen 6 and 7 selections. Stored as ids so the app and the API
            // agree on the vocabulary without a lookup table.
            $table->string('intent', 40)->nullable();
            $table->string('primary_goal', 40)->nullable();

            $table->timestamp('onboarding_completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
