<?php

namespace Tests\Feature\Support;

use App\Domain\Support\SupportCategory;
use App\Models\LoanApplication;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\LoanApplicationService;
use App\Services\Support\SupportCaseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eight taps, one request for help.
 *
 * The rest of the suite runs on one in-memory SQLite connection, which
 * makes every "concurrent" test in it sequential. That is enough to check
 * logic and useless for checking a race — so this forks eight real
 * processes against a shared database file, the same way Module 29's
 * notification idempotency is proved.
 *
 * Two races are checked here, and they are different.
 *
 * The idempotency key: eight taps of "Get help" must produce one case. The
 * unique index on (user_id, idempotency_key) decides that.
 *
 * The reference: eight *different* customers asking at once must each get a
 * distinct FE-SUP number. That one is not a duplicate-suppression problem —
 * every request is legitimate — and the read-then-write that allocates
 * references elsewhere in the codebase would give two of them the same
 * number or fail one outright.
 */
class SupportConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to prove this rather than simulate it.');
        }

        $this->database = tempnam(sys_get_temp_dir(), 'fynn_support').'.sqlite';
        touch($this->database);

        config(['database.connections.concurrency' => [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'concurrency']);

        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);

        // Applications need the product catalogue.
        Artisan::call('db:seed', ['--database' => 'concurrency', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('concurrency');

        if (isset($this->database) && file_exists($this->database)) {
            @unlink($this->database);
        }

        parent::tearDown();
    }

    /** Runs $work in eight forked children and waits for all of them. */
    private function inParallel(callable $work): void
    {
        $children = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('could not fork');
            }

            if ($pid === 0) {
                try {
                    // A handle inherited across a fork is shared, and
                    // sharing one would make this a single-connection test.
                    DB::purge('concurrency');
                    DB::reconnect('concurrency');
                    $work($i);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    public function test_eight_taps_of_get_help_create_one_request(): void
    {
        $user = User::factory()->create();

        $this->inParallel(function () use ($user) {
            app(SupportCaseService::class)->open(
                user: $user,
                category: SupportCategory::SubmissionStatus,
                subject: 'Help with application submission status',
                description: 'I am not sure whether my application was received.',
                idempotencyKey: 'application:1:get_help',
            );
        });

        $this->assertSame(
            1,
            SupportCase::query()->where('user_id', $user->getKey())->count(),
            'A customer tapping Get help eight times opened more than one request.',
        );
    }

    public function test_eight_customers_asking_at_once_each_get_their_own_reference(): void
    {
        $users = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $users[] = User::factory()->create();
        }

        $this->inParallel(function ($i) use ($users) {
            app(SupportCaseService::class)->open(
                user: $users[$i],
                category: SupportCategory::General,
                subject: 'A question about my account',
                description: 'I would like to understand how this works.',
            );
        });

        $references = SupportCase::query()->pluck('reference');

        // Every request landed...
        $this->assertCount(
            self::WORKERS,
            $references,
            'A legitimate request was lost to a reference collision.',
        );

        // ...and no two customers were handed the same number to quote.
        $this->assertSame(
            self::WORKERS,
            $references->unique()->count(),
            'Two customers were given the same support reference.',
        );
    }

    /**
     * The same guarantee, for applications.
     *
     * Module 30's allocator was written for support references and then the
     * measurement showed the application path had the defect it exists to
     * avoid: eight concurrent creations produced four rows, four customers
     * losing an application to a reference collision. Both now use
     * SequentialReference, and this is the test that says so.
     */
    public function test_eight_customers_applying_at_once_each_get_their_own_application(): void
    {
        $users = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $user = User::factory()->create();
            $user->ensureProfiles();
            $user->financialProfile->update([
                'monthly_income' => 150000,
                'monthly_expenses' => 40000,
                'existing_emi' => 10000,
                'savings' => 600000,
            ]);
            $users[] = $user;
        }

        $this->inParallel(function ($i) use ($users) {
            app(LoanApplicationService::class)->create($users[$i], [
                'product_id' => 'prod_bl_b',
                'amount' => 1000000,
                'tenure_months' => 60,
            ]);
        });

        $references = LoanApplication::pluck('reference');

        $this->assertCount(
            self::WORKERS,
            $references,
            'A customer lost their application to a reference collision.',
        );
        $this->assertSame(self::WORKERS, $references->unique()->count());
    }

    public function test_two_customers_using_the_same_key_get_their_own_request(): void
    {
        // The key is scoped to the customer. A shared client-side string —
        // "application:1:get_help" is not unique across people — must not
        // make one customer's request suppress another's.
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->inParallel(function ($i) use ($first, $second) {
            app(SupportCaseService::class)->open(
                user: $i % 2 === 0 ? $first : $second,
                category: SupportCategory::General,
                subject: 'A question about my account',
                description: 'I would like to understand how this works.',
                idempotencyKey: 'the-same-string',
            );
        });

        $this->assertSame(2, SupportCase::count());
    }
}
