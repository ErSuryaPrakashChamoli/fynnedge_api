<?php

namespace App\Console\Commands;

use App\Domain\Demo\ReservedIdentities;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Removes the demo customers and the development administrator.
 *
 * The counterpart to DemoFixtureSeeder and AdminUserSeeder, and the reason
 * both of those label what they write: a database that has been used for
 * development carries invented customers with invented loan applications,
 * and every report run against it afterwards counts them.
 *
 * It removes two populations and nothing else: the customers the fixture
 * seeder writes, and the ones the Flutter contract test leaves behind after
 * signing in against a running backend. Both are named individually in
 * ReservedIdentities, so a real customer cannot be caught by a rule getting
 * broader. Everything those customers own goes with them through the
 * foreign keys, which cascade.
 *
 * `--dry-run` lists what would go without touching anything, which is the
 * only way to inspect the effect when `--force` is being used from a script.
 */
class DemoPurgeCommand extends Command
{
    protected $signature = 'demo:purge
        {--admin : Also remove the development administrator}
        {--dry-run : List what would be removed and change nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Remove the demo fixture and contract-test customers, and optionally the development administrator';

    private const DEV_ADMIN_EMAIL = 'admin@fynnedge.test';

    public function handle(): int
    {
        /*
         * Matched against an explicit list of reserved numbers, never a
         * prefix. A prefix is a rule about numbers nobody has seen, and the
         * day a real customer holds one that matches, this command deletes
         * their applications and consents without anybody having decided
         * that. ReservedIdentities names every one, and a number not on the
         * list is not touched.
         */
        $customers = User::query()
            ->whereIn('mobile', ReservedIdentities::all())
            ->get();

        $admin = $this->option('admin')
            ? AdminUser::query()->where('email', self::DEV_ADMIN_EMAIL)->first()
            : null;

        if ($customers->isEmpty() && $admin === null) {
            $this->components->info('Nothing to remove.');

            return self::SUCCESS;
        }

        $this->components->info($this->option('dry-run')
            ? 'These would be removed (nothing will change):'
            : 'This will permanently remove:');

        foreach ($customers as $customer) {
            $this->line(sprintf(
                '  customer #%d  %s  %s  [%s]',
                $customer->getKey(),
                $customer->mobile,
                $customer->name ?? '—',
                in_array($customer->mobile, ReservedIdentities::FIXTURE_MOBILES, true)
                    ? 'fixture seeder'
                    : 'Flutter contract test',
            ));
        }

        if ($admin !== null) {
            $this->line("  administrator  {$admin->email}");
        }

        // Everything reachable from those customers, so the operator sees
        // the size of what cascades rather than only the row count.
        $this->newLine();
        $this->components->twoColumnDetail('Loan applications', (string) $this->countOwned($customers, 'loan_applications'));
        $this->components->twoColumnDetail('Documents', (string) $this->countOwned($customers, 'documents'));
        $this->components->twoColumnDetail('Support cases', (string) $this->countOwned($customers, 'support_cases'));
        $this->components->twoColumnDetail('Notifications', (string) $this->countOwned($customers, 'notifications'));
        $this->components->twoColumnDetail('Consents', (string) $this->countOwned($customers, 'consents'));

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — nothing was removed.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Remove these permanently?', false)) {
            $this->components->warn('Nothing was removed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($customers, $admin) {
            // Deleted one at a time rather than with a mass delete, so the
            // cascades run against known ids and a mistake in the filter
            // cannot take a table with it.
            foreach ($customers as $customer) {
                $customer->delete();
            }

            $admin?->delete();
        });

        $this->newLine();
        $this->components->info(sprintf(
            'Removed %d demo customer(s)%s.',
            $customers->count(),
            $admin !== null ? ' and the development administrator' : '',
        ));

        if ($admin === null && $this->option('admin') === false) {
            $this->components->warn(
                'The development administrator was left in place. Pass --admin to remove it too.',
            );
        }

        return self::SUCCESS;
    }

    /** @param Collection<int, User> $customers */
    private function countOwned($customers, string $table): int
    {
        if ($customers->isEmpty()) {
            return 0;
        }

        return DB::table($table)
            ->whereIn('user_id', $customers->pluck('id'))
            ->count();
    }
}
