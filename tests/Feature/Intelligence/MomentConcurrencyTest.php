<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\FinancialMomentType;
use App\Models\FinancialMoment;
use App\Models\User;
use App\Services\Intelligence\MomentRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eight refreshes, one milestone.
 *
 * A customer who reaches half of a goal has done so once. The screen that
 * tells them can be pulled to refresh, opened on two devices and reloaded
 * by a flaky connection, and the timeline must still say it happened once —
 * a history that repeats itself is not a history.
 *
 * The rest of the suite runs on one in-memory SQLite connection, so every
 * "concurrent" test in it is really sequential and would pass against a
 * read-then-write that duplicates in production. This forks eight real
 * processes against a shared database file, the way Modules 29 and 30 do.
 */
class MomentConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to prove this rather than simulate it.');
        }

        $this->database = tempnam(sys_get_temp_dir(), 'fynn_moments').'.sqlite';
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

    public function test_eight_refreshes_record_one_milestone(): void
    {
        $user = User::factory()->create();

        $this->inParallel(function () use ($user) {
            app(MomentRecorder::class)->record(
                user: $user,
                type: FinancialMomentType::GoalMilestone,
                title: 'A milestone worth noting',
                description: 'You are 50% of the way to your goal.',
                idempotencyKey: 'goal:1:milestone:50',
                rule: 'goal.progress crossed 50%',
            );
        });

        $this->assertSame(
            1,
            FinancialMoment::query()->where('user_id', $user->getKey())->count(),
            'A refreshed screen celebrated the same milestone more than once.',
        );
    }

    public function test_only_one_worker_believes_it_recorded_it(): void
    {
        $user = User::factory()->create();

        /*
         * `wasCreated` is what a caller reads to decide whether anything
         * further should happen once per moment. If several workers
         * believed they created it, that would happen several times even
         * though the table holds one row — so the flag has to come from the
         * insert, not from a read taken before it.
         */
        $this->inParallel(function ($i) use ($user) {
            $recorded = app(MomentRecorder::class)->record(
                user: $user,
                type: FinancialMomentType::GoalMilestone,
                title: 'A milestone worth noting',
                description: 'You are 50% of the way to your goal.',
                idempotencyKey: 'goal:1:milestone:50',
                rule: 'goal.progress crossed 50%',
            );

            if ($recorded->wasCreated) {
                DB::connection('concurrency')->table('financial_observations')->insert([
                    'user_id' => $user->getKey(),
                    'reason' => 'claimed_'.$i,

                    /*
                     * Distinct per worker, deliberately.
                     *
                     * Module 35 put a unique index on
                     * (user_id, follows_id, state_digest), and eight rows
                     * left at the column defaults would collide on it —
                     * which would make this assertion pass because of that
                     * index rather than because of `wasCreated`, and prove
                     * nothing about the flag it exists to check.
                     */
                    'state_digest' => 'claimed_'.$i,
                    'observed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->assertSame(
            1,
            DB::connection('concurrency')->table('financial_observations')
                ->where('reason', 'like', 'claimed_%')->count(),
            'More than one worker thought it had recorded the moment.',
        );
    }

    public function test_genuinely_different_milestones_all_land(): void
    {
        // The counterpart: the defence must not be swallowing real history.
        $user = User::factory()->create();

        $this->inParallel(function ($i) use ($user) {
            app(MomentRecorder::class)->record(
                user: $user,
                type: FinancialMomentType::GoalMilestone,
                title: 'A milestone worth noting',
                description: 'You passed a milestone.',
                idempotencyKey: 'goal:'.$i.':milestone:50',
                rule: 'goal.progress crossed 50%',
            );
        });

        $this->assertSame(self::WORKERS, FinancialMoment::count());
    }

    public function test_two_customers_hitting_the_same_milestone_each_get_theirs(): void
    {
        // The key is scoped to the customer. "goal:1:milestone:50" is not
        // unique across people, and one customer's history must not
        // suppress another's.
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->inParallel(function ($i) use ($first, $second) {
            app(MomentRecorder::class)->record(
                user: $i % 2 === 0 ? $first : $second,
                type: FinancialMomentType::GoalMilestone,
                title: 'A milestone worth noting',
                description: 'You are 50% of the way to your goal.',
                idempotencyKey: 'goal:1:milestone:50',
                rule: 'goal.progress crossed 50%',
            );
        });

        $this->assertSame(2, FinancialMoment::count());
    }
}
