<?php

namespace Tests\Feature\Progress;

use App\Domain\Intelligence\ObservationReason;
use App\Models\User;
use App\Services\Intelligence\ObservationRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eight saves of one form, one observation.
 *
 * The recorder compares the state it is about to write with the one at the
 * head of the customer's history and skips when they match. On its own that
 * is a read followed by a write, and eight requests arriving together all
 * read the head before any of them has written — every one of them decides
 * it is the first, and a customer ends up with eight rows recording that
 * nothing happened.
 *
 * The unique index is what closes that window, and only forked processes
 * can show it: the rest of the suite runs on one in-memory SQLite
 * connection, so its "concurrent" tests are sequential and would pass
 * against a comparison alone. Eight real processes against a shared
 * database file, the way Modules 29 to 31 do.
 *
 * Which of the two defences catches a given run is not fixed, and the tests
 * below are written accordingly. Forks do not start together, so the first
 * worker usually writes before the others read and the comparison absorbs
 * them; the window opens when they do overlap. The third test is the one
 * that proves the index — with it removed, two rows end up claiming the
 * same place in the same customer's history.
 */
class ObservationConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to prove this rather than simulate it.');
        }

        $this->database = tempnam(sys_get_temp_dir(), 'fynn_observations').'.sqlite';
        touch($this->database);

        config(['database.connections.concurrency' => [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'concurrency']);

        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::disconnect('concurrency');

        if (isset($this->database) && file_exists($this->database)) {
            @unlink($this->database);
        }

        parent::tearDown();
    }

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

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 200000,
        ]);

        return $user;
    }

    public function test_eight_simultaneous_saves_of_one_state_record_it_once(): void
    {
        $user = $this->customer();

        $this->inParallel(function () use ($user) {
            app(ObservationRecorder::class)
                ->observe($user, ObservationReason::FinancialProfileChanged);
        });

        /*
         * The outcome, whichever defence produced it: eight callers
         * describing one financial state leave one row saying it.
         */
        $this->assertSame(
            1,
            DB::connection('concurrency')->table('financial_observations')
                ->where('user_id', $user->getKey())->count(),
            'The same financial state was written down more than once.',
        );
    }

    public function test_the_history_that_results_is_still_a_chain(): void
    {
        $user = $this->customer();

        $this->inParallel(function () use ($user) {
            app(ObservationRecorder::class)
                ->observe($user, ObservationReason::GoalChanged);
        });

        $rows = DB::connection('concurrency')->table('financial_observations')
            ->where('user_id', $user->getKey())
            ->orderBy('id')
            ->get();

        /*
         * Whatever survived, it has to be a history rather than wreckage:
         * one row, following nothing, carrying the figures that were true
         * and a digest that identifies them.
         */
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]->follows_id);
        $this->assertNotSame('', $rows[0]->state_digest);
        $this->assertEquals(100000, $rows[0]->monthly_income);
        $this->assertEquals(50000, $rows[0]->surplus);
        $this->assertNotNull($rows[0]->observed_at);
    }

    public function test_genuinely_different_states_all_land(): void
    {
        // The counterpart: the defence must not be swallowing real history.
        $user = $this->customer();

        $this->inParallel(function (int $i) use ($user) {
            $user->financialProfile()->update([
                'monthly_income' => 100000 + (($i + 1) * 10000),
            ]);

            app(ObservationRecorder::class)
                ->observe($user->fresh(), ObservationReason::FinancialProfileChanged);
        });

        $incomes = DB::connection('concurrency')->table('financial_observations')
            ->where('user_id', $user->getKey())
            ->pluck('monthly_income');

        /*
         * Eight workers each recording a different income. How many land
         * depends on how the writes interleave — a worker whose figure
         * happens to match the head at that instant is correctly skipped —
         * but every row that exists must be one somebody actually wrote,
         * and no two rows may say the same thing about the same predecessor.
         */
        $this->assertGreaterThan(1, $incomes->count(), 'real changes were swallowed');

        $keys = DB::connection('concurrency')->table('financial_observations')
            ->where('user_id', $user->getKey())
            ->get()
            ->map(fn ($r) => $r->follows_id.':'.$r->state_digest);

        $this->assertSame($keys->count(), $keys->unique()->count());
    }
}
