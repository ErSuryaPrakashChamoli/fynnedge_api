<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('type', 20);
            $table->string('display_name', 200);

            // Kept for the customer to recognise the file. Never used to
            // build a storage path.
            $table->string('original_filename', 255);

            // Determined by the server from the file's own bytes, not from
            // what the client claimed.
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');

            // Where the bytes are. Never serialised to the customer: a
            // storage key is FynnEdge's internal address for a file, and
            // publishing it invites people to try it.
            $table->string('storage_disk', 40);
            $table->string('storage_key', 255);

            // 'uploaded' or 'failed'. There is no verification process, so
            // there is no verified state to be in.
            $table->string('status', 20)->default('uploaded');

            $table->timestamps();

            // The row outlives the file: deleting a document removes the
            // bytes and keeps the record that it existed and was removed.
            $table->softDeletes();

            $table->index(['user_id', 'deleted_at', 'id']);
            $table->index(['user_id', 'type']);
            $table->unique('storage_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
