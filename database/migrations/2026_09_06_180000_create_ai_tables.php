<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->timestamps();

            $table->index(['user_id', 'updated_at', 'id']);
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // 'user' or 'assistant'. There is no 'system' row: a system
            // prompt is not something the customer said, and storing one
            // here would put it in their transcript.
            $table->string('role', 20);
            $table->text('content');

            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('ai_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // A key from the memory policy allowlist, never free text.
            $table->string('key', 60);
            $table->string('value', 200);

            // Where it came from: which conversation, or which flow.
            $table->string('source', 60);

            $table->timestamps();

            // One value per key per customer: remembering a preference twice
            // is a contradiction waiting to be read back.
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_memories');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
