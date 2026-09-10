<?php

namespace Tests\Feature\Decisions;

use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionType;
use App\Models\DecisionContext;
use App\Models\User;
use App\Services\Decisions\DecisionRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eight slider steps, one exploration.
 *
 * A customer dragging an amount slider fires a simulation on every step,
 * and the ones landing on the same figures are one exploration rather than
 * eight. A history that repeats itself is not a history, and the customer
 * would stop reading it.
 *
 * The rest of the suite runs on one in-memory SQLite connection, which
 * makes every "concurrent" test in it sequential. This forks eight real
 * processes against a shared file, as Modules 29 to 31 do.
 */
class DecisionConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to prove this rather than simulate it.');
        }

        $this->database = tempnam(sys_get_temp_dir(), 'fynn_decisions').'.sqlite';
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

    private function snapshot(int $amount = 1000000): DecisionSnapshot
    {
        return DecisionSnapshot::of([
            'amount' => $amount,
            'tenure_months' => 60,
            'interest_rate' => 13.25,
            'emi' => 22881,
            'monthly_income' => 150000,
        ]);
    }

    public function test_eight_identical_explorations_are_recorded_once(): void
    {
        $user = User::factory()->create();

        $this->inParallel(function () use ($user) {
            app(DecisionRecorder::class)->record(
                user: $user,
                type: DecisionType::Simulation,
                snapshot: $this->snapshot(),
                productCode: 'prod_bl_b',
            );
        });

        $this->assertSame(
            1,
            DecisionContext::query()->where('user_id', $user->getKey())->count(),
            'The same exploration was recorded more than once.',
        );
    }

    public function test_only_one_worker_believes_it_recorded_it(): void
    {
        $user = User::factory()->create();

        /*
         * `wasCreated` is what a caller reads to decide whether anything
         * should happen once per exploration. Several workers believing
         * they created it would mean that happening several times, even
         * with one row in the table.
         */
        $this->inParallel(function ($i) use ($user) {
            $recorded = app(DecisionRecorder::class)->record(
                user: $user,
                type: DecisionType::Simulation,
                snapshot: $this->snapshot(),
                productCode: 'prod_bl_b',
            );

            if ($recorded->wasCreated) {
                DB::connection('concurrency')->table('decision_contexts')->insert([
                    'user_id' => $user->getKey(),
                    'type' => 'claimed_'.$i,
                    'snapshot' => '{}',
                    'idempotency_key' => 'claim:'.$i,
                    'occurred_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->assertSame(
            1,
            DecisionContext::query()->where('type', 'like', 'claimed_%')->count(),
            'More than one worker thought it had recorded the exploration.',
        );
    }

    public function test_genuinely_different_explorations_all_land(): void
    {
        // The counterpart: deduplication must not be swallowing real
        // explorations.
        $user = User::factory()->create();

        $this->inParallel(function ($i) use ($user) {
            app(DecisionRecorder::class)->record(
                user: $user,
                type: DecisionType::Simulation,
                snapshot: $this->snapshot(1000000 + ($i * 100000)),
                productCode: 'prod_bl_b',
            );
        });

        $this->assertSame(self::WORKERS, DecisionContext::count());
    }

    public function test_two_customers_exploring_the_same_thing_each_get_their_own(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->inParallel(function ($i) use ($first, $second) {
            app(DecisionRecorder::class)->record(
                user: $i % 2 === 0 ? $first : $second,
                type: DecisionType::Simulation,
                snapshot: $this->snapshot(),
                productCode: 'prod_bl_b',
            );
        });

        $this->assertSame(2, DecisionContext::count());
    }
}
