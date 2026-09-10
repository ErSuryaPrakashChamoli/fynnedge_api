<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has been said on a support case.
 *
 * Not a chat. There is no advisor to chat with, no presence, no typing
 * indicator and no delivery receipt, because NO ADVISOR OR CHAT PROVIDER IS
 * CONNECTED. What this is: a way for a customer to add information to a
 * request that is already open, which is the thing they actually need when
 * somebody eventually asks them a question.
 *
 * `author_type` is written by the server and never by a request. A customer
 * able to set it to `advisor` could put words in FynnEdge's mouth on their
 * own case — and then quote them.
 *
 * The `advisor` author type exists here with no write path anywhere in the
 * codebase. That is deliberate: the model is ready for an advisor
 * interface, and inventing an endpoint for one would be inventing the
 * advisor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_case_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('support_case_id')
                ->constrained()
                ->cascadeOnDelete();

            // 'customer', 'advisor' or 'system'. Server-decided.
            $table->string('author_type', 20);

            // Who, when there is a who. Null for system messages.
            $table->unsignedBigInteger('author_id')->nullable();

            $table->text('body');

            $table->timestamps();

            $table->index(['support_case_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_messages');
    }
};
