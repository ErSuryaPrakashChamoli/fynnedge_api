<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('category', 40)->default('other');
            $table->decimal('target_amount', 14, 2);
            $table->date('target_date');
            $table->decimal('saved_amount', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_goals');
    }
};
