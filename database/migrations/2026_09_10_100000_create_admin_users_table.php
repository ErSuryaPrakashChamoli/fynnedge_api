<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FynnEdge staff, who are not customers.
 *
 * A separate table rather than a flag on `users`, because the two are
 * different kinds of account and the difference is the point. A customer is
 * a mobile number that proves itself with a one-time code and holds no
 * password at all; a member of staff is a person with credentials and a
 * browser session. Putting both in one table means every query about
 * customers has to remember to exclude staff, and the day one forgets is
 * the day an administrator appears in a customer count — or a customer
 * acquires an admin flag.
 *
 * Nothing here references the customer tables. The only link staff have to
 * a customer is through the records they act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);

            // Unique: it is the login identity, and two rows sharing it
            // would make "who signed in" unanswerable.
            $table->string('email', 190)->unique();

            // Always a hash. Nothing writes this column directly — the model
            // casts it through the configured hasher.
            $table->string('password');

            /*
             * Stored as a string against the AdminRole enum rather than a
             * native ENUM column, matching how every other status in this
             * schema is held: the meaning lives in PHP where it can be
             * reasoned about, and adding a role does not need a migration
             * that rewrites the table.
             */
            $table->string('role', 40);

            /*
             * Whether this person may still sign in.
             *
             * Disabling rather than deleting, because their name is on
             * records that have to keep making sense after they leave.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamp('last_login_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            // The panel's own listing: who is active, by role.
            $table->index(['is_active', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
