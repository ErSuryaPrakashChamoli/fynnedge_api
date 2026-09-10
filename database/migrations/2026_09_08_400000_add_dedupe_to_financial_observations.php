<?php

use App\Services\Intelligence\ObservationRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An observation identical to the one it follows is not a new observation.
 *
 * Module 31 recorded unconditionally: a caller had decided something
 * meaningful happened, and second-guessing it in the recorder would have
 * put that decision in two places. That was right about where the decision
 * belongs and wrong about what happens when the caller is mistaken — saving
 * the financial profile form without changing a figure wrote another row
 * saying exactly what the row above it said. Ten saves, ten observations,
 * and a customer with "history" that records nothing having happened.
 *
 * Two columns make the rule enforceable:
 *
 *   state_digest  the observed figures, hashed. Not the reason: a goal
 *                 created a minute after a profile edit describes the same
 *                 financial state, and the state is what an observation is.
 *
 *   follows_id    the observation this one comes after, or 0 for a
 *                 customer's first. Deliberately not a foreign key and
 *                 deliberately not nullable — it is an ordering marker, and
 *                 a NULL would be distinct from every other NULL in the
 *                 unique index below, which is exactly the case that needs
 *                 catching.
 *
 * Unique on all three, reached through insertOrIgnore. A read-then-write
 * has a window between its two statements and two concurrent saves of the
 * same form aim straight at it; only the database can settle that. The same
 * mechanism Modules 29, 30 and 31 use for moments and notifications.
 *
 * A customer who returns to a state they held before is observed again, and
 * should be: the row before it is different, so the fact is new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_observations', function (Blueprint $table) {
            $table->string('state_digest', 64)->default('');
            $table->unsignedBigInteger('follows_id')->default(0);
        });

        // Existing rows, given the identity they were written without. Each
        // customer's observations in the order they were observed, so the
        // chain reads the same as it would have been written.
        $this->backfill();

        Schema::table('financial_observations', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'follows_id', 'state_digest'],
                'financial_observations_state_unique',
            );
        });
    }

    private function backfill(): void
    {
        $rows = DB::table('financial_observations')
            ->orderBy('user_id')
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get();

        $previousByUser = [];

        foreach ($rows as $row) {
            DB::table('financial_observations')
                ->where('id', $row->id)
                ->update([
                    'follows_id' => $previousByUser[$row->user_id] ?? 0,
                    'state_digest' => ObservationRecorder::digestOf(
                        score: $row->score,
                        scoreBand: $row->score_band,
                        scoreModelVersion: $row->score_model_version,
                        monthlyIncome: (string) $row->monthly_income,
                        monthlyOutgo: (string) $row->monthly_outgo,
                        surplus: (string) $row->surplus,
                        emiRatio: (string) $row->emi_ratio,
                        expenseRatio: (string) $row->expense_ratio,
                        emergencyMonths: (string) $row->emergency_months,
                    ),
                ]);

            $previousByUser[$row->user_id] = $row->id;
        }
    }

    public function down(): void
    {
        Schema::table('financial_observations', function (Blueprint $table) {
            $table->dropUnique('financial_observations_state_unique');
            $table->dropColumn(['state_digest', 'follows_id']);
        });
    }
};
