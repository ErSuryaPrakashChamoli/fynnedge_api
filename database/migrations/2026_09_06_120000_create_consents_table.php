<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only ledger of consent decisions.
     *
     * Every grant and every withdrawal is a new row. Nothing is updated and
     * nothing is deleted, so the question "what did this customer agree to,
     * under which version, and when" always has an answer — including for
     * versions that have since been replaced.
     */
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The structured type, never a UI label: a renamed switch must
            // not orphan the evidence of what was agreed.
            $table->string('type', 40);

            // The wording this decision was made under. A later revision is
            // a new version and a new row, never an edit to this one.
            $table->string('version', 20);

            $table->string('status', 20);

            $table->timestamp('granted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            // Where the decision was made — which screen, or which flow.
            $table->string('source', 60);

            $table->timestamps();

            // "The customer's current position on X" and "everything they
            // have ever decided", which are the only two reads there are.
            $table->index(['user_id', 'type', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
