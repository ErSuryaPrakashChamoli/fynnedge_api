<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer has chosen about being notified.
 *
 * Deliberately narrow. A preference here changes how FynnEdge reaches out;
 * it never changes what FynnEdge records. The notification centre always
 * holds everything, because a customer who turned off push and then opens
 * the app must still find out that their application needs a document.
 *
 * `muted_categories` may only ever contain categories in which every
 * notification type is non-transactional — today, financial health and
 * goals. Muting applications or documents is refused, because those are the
 * messages that exist to let somebody act on something they started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Whether FynnEdge may push at all. Off changes nothing about
            // what the notification centre contains.
            $table->boolean('push_enabled')->default(true);

            $table->json('muted_categories')->nullable();

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
