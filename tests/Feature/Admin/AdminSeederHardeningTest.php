<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Demo\ReservedIdentities;
use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * The first administrator, and the customers that are not people.
 *
 * Two hardening changes are guarded here. The seeder used to hold a working
 * password in a tracked constant, and the cleanup command used to find demo
 * customers with a prefix. Both were defensible until they were not: a
 * committed credential only has to be reachable once, and a prefix is a
 * claim about numbers nobody has seen yet.
 */
class AdminSeederHardeningTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // The seeder holds no credential
    // -----------------------------------------------------------------

    public function test_the_seeder_source_contains_no_usable_password(): void
    {
        $source = file_get_contents(database_path('seeders/AdminUserSeeder.php'));

        // A password assigned to a constant or a literal is what this is
        // looking for — not the environment reads, which are the point.
        $this->assertDoesNotMatchRegularExpression(
            '/(?:PASSWORD|password)\s*=\s*[\'"][^\'"]{8,}[\'"]/',
            $source,
            'AdminUserSeeder assigns a literal password. It must come from the '
            .'environment or be generated at run time.',
        );
    }

    public function test_development_creates_an_admin_without_a_configured_password(): void
    {
        config(['app.env' => 'local']);
        $this->app['env'] = 'local';

        $this->seed(AdminUserSeeder::class);

        $admin = AdminUser::query()->where('email', 'admin@fynnedge.test')->first();

        $this->assertNotNull($admin, 'No development administrator was created.');
        $this->assertSame(AdminRole::SuperAdmin, $admin->role());
        $this->assertTrue($admin->is_active);

        // Stored hashed, whatever it was.
        $this->assertStringStartsWith('$2y$', $admin->password);
    }

    public function test_development_uses_a_configured_password_when_one_is_given(): void
    {
        $this->app['env'] = 'local';
        putenv('FYNNEDGE_ADMIN_PASSWORD=ChosenByTheDeveloper1');

        try {
            $this->seed(AdminUserSeeder::class);

            $admin = AdminUser::query()->where('email', 'admin@fynnedge.test')->firstOrFail();

            $this->assertTrue(
                Hash::check('ChosenByTheDeveloper1', $admin->password),
                'A configured FYNNEDGE_ADMIN_PASSWORD was not used.',
            );
        } finally {
            putenv('FYNNEDGE_ADMIN_PASSWORD');
        }
    }

    public function test_an_existing_administrator_keeps_its_password(): void
    {
        $this->app['env'] = 'local';

        $existing = AdminUser::create([
            'name' => 'Already Here',
            'email' => 'admin@fynnedge.test',
            'password' => 'TheOneTheyAlreadyHave1',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->seed(AdminUserSeeder::class);

        $this->assertTrue(
            Hash::check('TheOneTheyAlreadyHave1', $existing->fresh()->password),
            'A deployment step silently reset an administrator\'s password.',
        );
    }

    // -----------------------------------------------------------------
    // Production refuses to invent one
    // -----------------------------------------------------------------

    public function test_production_refuses_without_configured_credentials(): void
    {
        $this->app['env'] = 'production';
        putenv('FYNNEDGE_ADMIN_EMAIL');
        putenv('FYNNEDGE_ADMIN_PASSWORD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to create an administrator/');

        // Run directly rather than through `$this->seed()`: SeedCommand asks
        // Laravel's own "Application In Production" confirmation first, and
        // that prompt, not this seeder, is what the mocked console would
        // answer. The behaviour under test is seedProduction().
        (new AdminUserSeeder)->run();
    }

    public function test_production_refuses_a_short_password(): void
    {
        $this->app['env'] = 'production';
        putenv('FYNNEDGE_ADMIN_EMAIL=ops@example.com');
        putenv('FYNNEDGE_ADMIN_PASSWORD=short');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/at least 12 characters/');

            (new AdminUserSeeder)->run();
        } finally {
            putenv('FYNNEDGE_ADMIN_EMAIL');
            putenv('FYNNEDGE_ADMIN_PASSWORD');
        }
    }

    public function test_production_never_falls_back_to_a_development_account(): void
    {
        $this->app['env'] = 'production';
        putenv('FYNNEDGE_ADMIN_EMAIL');
        putenv('FYNNEDGE_ADMIN_PASSWORD');

        try {
            (new AdminUserSeeder)->run();
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(
            0,
            AdminUser::query()->count(),
            'A production run created an administrator despite refusing.',
        );
    }

    // -----------------------------------------------------------------
    // Reserved identities are named, not matched
    // -----------------------------------------------------------------

    public function test_every_reserved_identity_is_listed_explicitly(): void
    {
        $all = ReservedIdentities::all();

        $this->assertCount(8, $all, 'The reserved list changed size unexpectedly.');
        $this->assertSame($all, array_unique($all), 'The reserved list repeats a number.');

        foreach ($all as $mobile) {
            $this->assertMatchesRegularExpression('/^[6-9]\d{9}$/', $mobile);
        }
    }

    public function test_the_baseline_development_customer_is_never_reserved(): void
    {
        // The customer the Flutter app's own mock data mirrors. Removing it
        // would break the thing it exists for.
        $this->assertFalse(
            ReservedIdentities::isReserved((string) config('fynnedge.demo_mobile')),
            'The baseline development customer is on the purge list.',
        );
    }

    public function test_a_real_looking_number_is_not_reserved(): void
    {
        foreach (['9000000105', '9000000000', '9876543211', '9000000010'] as $mobile) {
            $this->assertFalse(
                ReservedIdentities::isReserved($mobile),
                "{$mobile} was treated as a demo identity. Matching must stay exact.",
            );
        }
    }

    public function test_purge_removes_only_reserved_customers(): void
    {
        $real = User::create(['mobile' => '9812345670']);
        $real->ensureProfiles();

        $baseline = User::create(['mobile' => (string) config('fynnedge.demo_mobile')]);
        $baseline->ensureProfiles();

        foreach (ReservedIdentities::all() as $mobile) {
            User::create(['mobile' => $mobile])->ensureProfiles();
        }

        $this->assertSame(10, User::query()->count());

        $this->artisan('demo:purge', ['--force' => true])->assertSuccessful();

        $remaining = User::query()->pluck('mobile')->all();
        sort($remaining);

        $this->assertSame(
            ['9812345670', (string) config('fynnedge.demo_mobile')],
            $remaining,
            'demo:purge removed something it should not have, or missed something it should.',
        );
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        foreach (ReservedIdentities::all() as $mobile) {
            User::create(['mobile' => $mobile])->ensureProfiles();
        }

        $before = User::query()->count();

        $this->artisan('demo:purge', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, User::query()->count(), 'A dry run deleted rows.');
    }
}
