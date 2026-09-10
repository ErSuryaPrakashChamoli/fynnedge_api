<?php

namespace Database\Seeders;

use App\Domain\Admin\AdminRole;
use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The first administrator.
 *
 * Two ways in, and which one runs is decided by the environment rather than
 * by a flag somebody passes:
 *
 *   Development / testing
 *     A Super Admin at a known address, so a fresh checkout can open the
 *     panel. The password comes from FYNNEDGE_ADMIN_PASSWORD if it is set,
 *     and is otherwise generated and printed once — never written down in
 *     the repository. Idempotent: an account that already exists keeps the
 *     password it has.
 *
 *   Production
 *     Nothing at all unless FYNNEDGE_ADMIN_EMAIL and FYNNEDGE_ADMIN_PASSWORD
 *     are both set, and then that account and no other. There is no default
 *     production password here and there must never be one: a well-known
 *     credential on a panel that can read every customer's finances is not
 *     a convenience, it is the whole breach.
 */
class AdminUserSeeder extends Seeder
{
    /** The address a developer signs in with. Not a credential. */
    private const DEV_EMAIL = 'admin@fynnedge.test';

    public function run(): void
    {
        app()->environment('production')
            ? $this->seedProduction()
            : $this->seedDevelopment();
    }

    /**
     * A working administrator for development, without a password in the
     * repository.
     *
     * There used to be a constant here holding one. It was guarded — the
     * production branch below never reads it — but a working credential for
     * a panel that reads every customer's finances does not become safe by
     * being commented "development only". It only has to be true once that
     * a deployment came up with APP_ENV unset and somebody ran the seeders.
     *
     * So the password is either given deliberately through the environment,
     * or generated and printed once. Neither is committed, and a developer
     * with a fresh checkout still gets in.
     *
     * An administrator that already exists keeps the password it has. A
     * deployment step must not silently reset somebody's credentials —
     * that is a reset, and a reset is something a person does on purpose.
     */
    private function seedDevelopment(): void
    {
        $email = (string) (env('FYNNEDGE_ADMIN_EMAIL') ?: self::DEV_EMAIL);

        if (AdminUser::query()->where('email', $email)->exists()) {
            $this->command?->info("Administrator {$email} already exists; password unchanged.");

            return;
        }

        $configured = env('FYNNEDGE_ADMIN_PASSWORD');
        $password = filled($configured) ? (string) $configured : Str::password(24);

        AdminUser::create([
            'name' => (string) env('FYNNEDGE_ADMIN_NAME', 'FynnEdge Admin'),
            'email' => $email,
            'password' => $password,
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->command?->info("Development Super Admin: {$email}");

        if (filled($configured)) {
            $this->command?->info('Password: taken from FYNNEDGE_ADMIN_PASSWORD.');

            return;
        }

        // Printed once, to the console of whoever ran the seeder, and stored
        // only as a hash. Nothing else will ever show it again.
        $this->command?->warn("Password: {$password}");
        $this->command?->warn('Shown once — it is stored hashed. Re-run after deleting the account to get a new one.');
    }

    /**
     * In production the credentials come from the environment or the seeder
     * declines to invent any.
     */
    private function seedProduction(): void
    {
        $email = env('FYNNEDGE_ADMIN_EMAIL');
        $password = env('FYNNEDGE_ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            throw new RuntimeException(
                'Refusing to create an administrator in production without '
                .'FYNNEDGE_ADMIN_EMAIL and FYNNEDGE_ADMIN_PASSWORD. There is '
                .'no default password for this panel.',
            );
        }

        if (strlen((string) $password) < 12) {
            throw new RuntimeException(
                'FYNNEDGE_ADMIN_PASSWORD must be at least 12 characters.',
            );
        }

        // Created once. An existing administrator is never silently given a
        // new password by a deployment step — that is a reset, and a reset
        // is something a person does on purpose.
        if (AdminUser::query()->where('email', $email)->exists()) {
            $this->command?->info('Administrator already exists; nothing to do.');

            return;
        }

        AdminUser::create([
            'name' => (string) env('FYNNEDGE_ADMIN_NAME', 'FynnEdge Administrator'),
            'email' => $email,
            'password' => $password,
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->command?->info('Administrator created from the environment.');
    }
}
