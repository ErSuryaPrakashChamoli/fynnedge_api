<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one identity field compared with the customer's profile.
 *
 * Values are stored only where a customer has a difference to resolve. A
 * matched field keeps no values at all: the comparison already happened, the
 * answer is "the same", and writing the identity value down again would put
 * it in the database for no purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_field_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_verification_id')->constrained()->cascadeOnDelete();

            $table->string('field', 40);
            $table->string('label', 60);

            // matched | mismatched | unable_to_compare
            $table->string('status', 20);

            // Why, when the answer is not simply matched.
            $table->string('reason', 240)->nullable();

            /*
             * Both sides of a difference, and only of a difference.
             *
             * Null on a match and null when nothing could be compared. The
             * customer needs to see what differs in order to decide what to
             * do about it; they do not need their own name read back to
             * them when it agreed.
             */
            $table->string('profile_value', 200)->nullable();
            $table->string('verified_value', 200)->nullable();

            $table->timestamps();

            $table->unique(['kyc_verification_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_field_matches');
    }
};
