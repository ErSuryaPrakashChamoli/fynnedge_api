<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FynnEdge identifies a customer by mobile number, not email/password.
 * The stock users table is adapted rather than replaced so Laravel's auth
 * plumbing keeps working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('mobile', 15)->unique()->after('id');
            $table->timestamp('last_login_at')->nullable()->after('password');
        });

        // SQLite cannot alter a column in place, so rebuild the two that
        // become optional under OTP auth.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mobile', 'last_login_at']);
        });
    }
};
