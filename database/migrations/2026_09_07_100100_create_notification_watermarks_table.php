<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last thing FynnEdge told a customer about a value that moves.
 *
 * One row per customer per watched value. It exists because "has this
 * changed since we last said so?" cannot be answered from the notification
 * itself without putting the value in the notification — and a FynnScore
 * band belongs on the score screen, not on a lock screen.
 *
 * `version` counts transitions and is what the notification's idempotency
 * key is built from. That is deliberate: a customer whose score falls a
 * band and recovers should be told twice, while two concurrent
 * recalculations of the same move must produce one notification. Keying on
 * the destination band alone would get the first case wrong; keying on a
 * timestamp would get the second wrong.
 *
 * No figure is stored — a band, which is the coarse label the product
 * already shows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_watermarks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('key', 60);
            $table->string('value', 60);
            $table->unsignedInteger('version')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_watermarks');
    }
};
