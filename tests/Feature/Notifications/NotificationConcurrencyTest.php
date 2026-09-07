<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\NotificationDecision;
use App\Domain\Notifications\NotificationType;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Eight processes, one event, one notification.
 *
 * The rest of the suite runs on one in-memory SQLite connection, which
 * means every "concurrent" test in it is really a sequential one. That is
 * enough to check logic and useless for checking a race: a read-then-write
 * would pass all of them and still produce duplicates the first time a
 * provider redelivered an event to two workers at once.
 *
 * So this test does not simulate concurrency. It forks eight real
 * processes against a real shared database file, has all eight record the
 * same decision, and counts the rows. What it proves is that the defence is
 * the unique index — not an `if exists` in PHP, which none of these
 * processes can see each other execute.
 *
 * It is deliberately awkward. The awkwardness is the point: a duplicate
 * notification about somebody's loan being declined is the kind of thing
 * that has to be impossible rather than unlikely.
 */
class NotificationConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to prove this rather than simulate it.');
        }

        // A file, not :memory:. Eight processes cannot share memory, and a
        // test that quietly fell back to one connection would be the
        // sequential test this exists to replace.
        $this->database = tempnam(sys_get_temp_dir(), 'fynn_concurrency').'.sqlite';
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

    private function decision(): NotificationDecision
    {
        return NotificationDecision::of(
            type: NotificationType::ApplicationApproved,
            title: 'The provider approved your application',
            body: 'The provider has approved your application. Open it to '
                .'see what they said and what happens next.',
            // One event. Every worker composes exactly this, because they
            // are all reacting to the same provider delivery.
            idempotencyKey: 'application:1:provider:acme:evt_9f2',
        );
    }

    /**
     * Runs $work in eight forked children and waits for all of them.
     *
     * Each child reconnects: a database handle inherited across a fork is
     * shared, and sharing one would make this a single-connection test
     * again.
     */
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
                    // A child that failed exits non-zero; the parent's row
                    // count is what the assertion actually rests on.
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    public function test_eight_workers_recording_one_event_create_one_notification(): void
    {
        $user = User::factory()->create();

        $this->inParallel(function () use ($user) {
            app(NotificationRecorder::class)->record($user, $this->decision());
        });

        $this->assertSame(
            1,
            Notification::query()->where('user_id', $user->getKey())->count(),
            'Eight concurrent deliveries of one event produced more than one '
            .'notification. The unique index is what has to prevent this.',
        );
    }

    public function test_only_one_worker_believes_it_created_the_notification(): void
    {
        $user = User::factory()->create();

        /*
         * `wasCreated` is what decides whether a push is queued. If two
         * workers both believed they created it, a customer would be
         * pushed twice for one event even though the table holds one row —
         * so the flag has to come from the insert, not from a later read.
         */
        $this->inParallel(function ($i) use ($user) {
            $recorded = app(NotificationRecorder::class)->record($user, $this->decision());

            if ($recorded->wasCreated) {
                DB::connection('concurrency')->table('notification_watermarks')->insert([
                    'user_id' => $user->getKey(),
                    'key' => 'claimed_'.$i,
                    'value' => 'yes',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->assertSame(
            1,
            DB::connection('concurrency')->table('notification_watermarks')
                ->where('key', 'like', 'claimed_%')->count(),
            'More than one worker thought it had created the notification.',
        );
    }

    public function test_two_genuinely_different_events_both_get_through(): void
    {
        // The counterpart, so the test above cannot be passing because
        // something is swallowing everything.
        $user = User::factory()->create();

        $this->inParallel(function ($i) use ($user) {
            app(NotificationRecorder::class)->record($user, NotificationDecision::of(
                type: NotificationType::ApplicationUnderReview,
                title: 'Your application is being reviewed',
                body: 'The provider is reviewing your application. There is '
                    .'nothing you need to do right now.',
                idempotencyKey: 'application:1:provider:acme:evt_'.$i,
            ));
        });

        $this->assertSame(
            self::WORKERS,
            Notification::query()->where('user_id', $user->getKey())->count(),
        );
    }
}
