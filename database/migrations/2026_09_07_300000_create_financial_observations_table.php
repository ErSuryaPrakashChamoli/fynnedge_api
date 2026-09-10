<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was true about a customer's money at one moment.
 *
 * This table exists because of what Module 31's audit found: FynnEdge kept
 * no financial history at all. `financial_profiles` is a single mutable row,
 * so editing an income destroyed the previous one irrecoverably, and there
 * was no record anywhere of what a FynnScore had been.
 *
 * That makes an entire class of claim impossible rather than merely
 * unimplemented. "Your income increased 12%", "your expenses are falling",
 * "your score improved by 6", "this goal has stalled" — none could be said
 * truthfully, because nothing had been observed to compare against.
 *
 * So FynnEdge starts observing. And it starts EMPTY: no row here is
 * reconstructed from today's profile, which means no existing customer
 * acquires a history they did not have. Until a customer has two
 * observations, the honest answer to "what has changed?" is that FynnEdge
 * does not know yet, and the intelligence layer says exactly that.
 *
 * Written only at meaningful change — a profile edit, a goal change, an
 * application milestone — never on a page view. An app open is not a
 * financial event, and a table that grew with screen refreshes would be
 * surveillance wearing an analytics hat.
 *
 * Figures only, and only the ones an engine already derives. No PAN, no
 * document, no provider payload, no account number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_observations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // What prompted this observation. An ObservationReason case.
            $table->string('reason', 40);

            /*
             * The FynnScore as it was, not as it would be recomputed today.
             *
             * Null when there was not enough profile to produce one — which
             * is itself a fact worth holding, because it is how "your score
             * became available" is later distinguishable from "your score
             * improved".
             */
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('score_band', 30)->nullable();

            // The model that produced it. A score compared across model
            // versions is not a comparison.
            $table->string('score_model_version', 20)->nullable();

            /*
             * The engine's own figures, stored as the engine serialises
             * them. Not recomputed here and not rounded differently.
             */
            $table->decimal('monthly_income', 14, 2)->default(0);
            $table->decimal('monthly_outgo', 14, 2)->default(0);
            $table->decimal('surplus', 14, 2)->default(0);
            $table->decimal('emi_ratio', 8, 2)->default(0);
            $table->decimal('expense_ratio', 8, 2)->default(0);
            $table->decimal('emergency_months', 8, 2)->default(0);

            $table->timestamp('observed_at');
            $table->timestamps();

            // The only query: this customer's observations, newest first.
            $table->index(['user_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_observations');
    }
};
