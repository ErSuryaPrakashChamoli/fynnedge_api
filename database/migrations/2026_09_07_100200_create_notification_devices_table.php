<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a push notification could be delivered, if there were anywhere to
 * send it from.
 *
 * NO PUSH PROVIDER IS CONNECTED. This table exists so the delivery boundary
 * is complete rather than imagined, and it stays empty until a customer's
 * app registers a token — nothing seeds one and no token is invented.
 *
 * The token is unique across the whole table, and that is a correctness
 * rule rather than tidiness: a device belongs to whoever is signed in on
 * it, and if the same token is registered by a second customer the first
 * registration must stop. Otherwise one customer's notifications would be
 * delivered to a handset another customer is holding.
 *
 * The token never appears in a response and is never logged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('token', 255);
            $table->string('platform', 20);

            // A customer switching push off on this device deactivates it
            // rather than removing it, so a device that comes back is
            // recognised. A device claimed by a different customer is
            // removed outright — see NotificationDeviceController.
            $table->boolean('active')->default(true);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('token');
            $table->index(['user_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_devices');
    }
};
