<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Compares the application database against the original SQLite file.
 *
 * The conversion to MySQL replaced a database nobody can rebuild with a
 * schema and a seeder that anybody can. That claim is only worth making if
 * it is checked, so this checks it: every table in the original is counted
 * in both places and the two numbers are put side by side.
 *
 * It reports counts and column names, never values. The original holds one
 * synthetic customer today, but the same command will be pointed at a
 * database holding real ones, and a verification tool that prints the rows
 * it is verifying becomes the leak it was meant to guard against.
 */
class DatabaseVerifyCommand extends Command
{
    protected $signature = 'database:verify
        {--original= : Path to the original SQLite file (default: database/database.sqlite)}
        {--strict : Treat tables that are empty in both databases as worth listing}
        {--allow-extra : Pass when the current database has gained rows, as it has once demo fixtures are loaded}';

    protected $description = 'Compare the current database against the original SQLite file';

    /**
     * Tables whose contents are bookkeeping rather than application data.
     *
     * `migrations` differs by design once a migration is added, and the
     * cache, queue and session tables hold whatever happened to be in
     * flight. Counting them would report a failure every time something
     * entirely normal happened.
     */
    private const NOT_APPLICATION_DATA = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    public function handle(): int
    {
        $path = $this->option('original') ?: database_path('database.sqlite');

        if (! is_readable($path)) {
            $this->components->error("Original SQLite file not readable: {$path}");

            return self::FAILURE;
        }

        $original = new PDO('sqlite:'.$path);
        $original->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $connection = DB::connection();

        $this->components->info(sprintf(
            'Original: %s   Current: %s (%s)',
            $path,
            $connection->getDatabaseName(),
            $connection->getDriverName(),
        ));

        $originalTables = $original
            ->query('SELECT name FROM sqlite_master WHERE type = "table" AND name NOT LIKE "sqlite_%" ORDER BY name')
            ->fetchAll(PDO::FETCH_COLUMN);

        $rows = [];
        $pass = 0;
        $differing = 0;
        $missing = 0;
        $skipped = 0;
        $originalTotal = 0;
        $currentTotal = 0;

        foreach ($originalTables as $table) {
            $originalCount = (int) $original->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();

            if (in_array($table, self::NOT_APPLICATION_DATA, true)) {
                $skipped++;

                continue;
            }

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                $rows[] = [$table, $originalCount, '—', 'MISSING'];
                $missing++;

                continue;
            }

            $currentCount = (int) $connection->table($table)->count();

            $originalTotal += $originalCount;
            $currentTotal += $currentCount;

            if ($originalCount === $currentCount) {
                $pass++;

                // An empty table matching an empty table is the common case
                // and says nothing; listing all forty of them buries the
                // rows that matter.
                if ($originalCount === 0 && ! $this->option('strict')) {
                    continue;
                }

                $rows[] = [$table, $originalCount, $currentCount, 'PASS'];

                continue;
            }

            /*
             * More rows than the original is a different finding from fewer.
             *
             * The question this command exists to answer is whether the
             * conversion lost anything, and a database that has since been
             * seeded with demo fixtures has not — it has gained. Reported
             * as EXTRA rather than folded into DIFFERS, so a real loss
             * stays visible in a database somebody has been using.
             */
            if ($this->option('allow-extra') && $currentCount > $originalCount) {
                $rows[] = [$table, $originalCount, $currentCount, 'EXTRA (+'.($currentCount - $originalCount).')'];
                $pass++;

                continue;
            }

            $rows[] = [$table, $originalCount, $currentCount, 'DIFFERS'];
            $differing++;
        }

        $this->newLine();
        $this->table(['TABLE', 'ORIGINAL', 'CURRENT', 'STATUS'], $rows);

        // Columns present in one database and not the other. A count can
        // match while the shape has drifted, and that drift is what breaks
        // the API rather than the row totals.
        $this->reportColumnDrift($original, $originalTables);

        $this->newLine();
        $this->components->twoColumnDetail('Tables compared', (string) ($pass + $differing + $missing));
        $this->components->twoColumnDetail('Matching', (string) $pass);
        $this->components->twoColumnDetail('Differing', (string) $differing);
        $this->components->twoColumnDetail('Missing', (string) $missing);
        $this->components->twoColumnDetail('Framework tables skipped', (string) $skipped);
        $this->components->twoColumnDetail('Application rows (original)', (string) $originalTotal);
        $this->components->twoColumnDetail('Application rows (current)', (string) $currentTotal);

        $this->newLine();

        if ($differing === 0 && $missing === 0) {
            $this->components->info('Data verification: PASS');

            return self::SUCCESS;
        }

        $this->components->error('Data verification: FAIL');

        return self::FAILURE;
    }

    /**
     * @param  list<string>  $tables
     */
    private function reportColumnDrift(PDO $original, array $tables): void
    {
        $connection = DB::connection();
        $drift = [];

        foreach ($tables as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $originalColumns = array_column(
                $original->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC),
                'name',
            );

            $currentColumns = $connection->getSchemaBuilder()->getColumnListing($table);

            foreach (array_diff($originalColumns, $currentColumns) as $column) {
                $drift[] = [$table, $column, 'only in original'];
            }

            foreach (array_diff($currentColumns, $originalColumns) as $column) {
                $drift[] = [$table, $column, 'only in current'];
            }
        }

        if ($drift === []) {
            $this->components->info('Column shape: identical across every compared table.');

            return;
        }

        $this->newLine();
        $this->components->warn('Column differences:');
        $this->table(['TABLE', 'COLUMN', 'WHERE'], $drift);
    }
}
