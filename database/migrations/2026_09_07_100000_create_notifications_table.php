<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What FynnEdge has told a customer.
 *
 * A row is the notification itself and nothing else. It holds the sentence
 * the customer reads, where it takes them, and whether they have read it.
 * It holds no provider payload, no extracted field, no identity value and
 * no storage key: everything a notification is about lives in the table it
 * belongs to, and this one carries a link to it rather than a copy of it.
 *
 * The unique index on (user_id, idempotency_key) is the whole duplicate
 * defence. Provider redelivery, queue retries, a double-tapped button and
 * two concurrent workers all compose the same key, and the database — not
 * a read-then-write in PHP — decides that only one row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type', 60);
            $table->string('category', 40);

            $table->string('title', 120);
            $table->string('body', 400);

            // Always a fynnedge:// link built by DeepLink, or nothing.
            $table->string('deep_link', 190)->nullable();

            $table->string('idempotency_key', 190);

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The defence, not an optimisation.
            $table->unique(['user_id', 'idempotency_key']);

            // The notification centre's only query: this customer's
            // notifications, newest first.
            $table->index(['user_id', 'id']);

            // The unread count, without reading a single body.
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
