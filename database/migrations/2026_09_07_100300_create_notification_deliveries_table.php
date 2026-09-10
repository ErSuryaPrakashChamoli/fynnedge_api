<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What happened when FynnEdge tried to push a notification somewhere.
 *
 *     NOTIFICATION CREATED  ≠  PUSH DELIVERED
 *
 * Kept in its own table for exactly that reason. A notification exists
 * because something happened to the customer's application; a delivery
 * exists because FynnEdge tried to reach a handset. A failed push must
 * never remove the first, and the customer must still find the message in
 * the notification centre — so delivery state lives here and the
 * notification row is not touched by it.
 *
 * `is_simulated` is never inferred. A mock provider records that it
 * simulated, and nothing anywhere reads a simulated delivery as a delivery.
 *
 * Unique on (notification_id, device_id): a retried job re-attempts the
 * same row rather than writing a second one, so "delivered twice" cannot be
 * a thing the table can say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('notification_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('notification_device_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('channel', 20);
            $table->string('provider', 40)->nullable();
            $table->boolean('is_simulated')->default(false);

            $table->string('status', 30);
            $table->string('reason', 60)->nullable();

            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            // Named explicitly: the generated name would be 69 characters,
            // and MySQL caps an identifier at 64.
            $table->unique(
                ['notification_id', 'notification_device_id'],
                'notification_deliveries_notification_device_unique',
            );
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
