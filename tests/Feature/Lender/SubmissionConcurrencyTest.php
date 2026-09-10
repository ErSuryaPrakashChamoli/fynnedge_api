<?php

namespace Tests\Feature\Lender;

use App\Domain\Consent\ConsentType;
use App\Domain\Lender\Fixture\FixtureScenario;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\Lender\LenderApplicationSubmissionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two taps, one application at the lender.
 *
 * This is the most consequential concurrent path in the product. Every
 * other duplicate costs a customer a repeated row; a duplicate here would
 * be a second real application in somebody's name at a real lender, and no
 * amount of tidying afterwards undoes that.
 *
 * The service claims the attempt inside a transaction with the application
 * locked and calls the provider only after, which is the right shape. It
 * had never been run concurrently: the rest of the suite shares one
 * in-memory SQLite connection, so its "twice" tests are sequential.
 *
 * What this measured, by removing one guard at a time with the barrier in
 * place:
 *
 *   transaction + unique index   one submission
 *   transaction only             one submission
 *   unique index only            one submission
 *   neither                      eight submissions
 *
 * So the two defences are genuinely redundant here, and either alone holds
 * on this driver — worth knowing, because SQLite has no SELECT ... FOR
 * UPDATE, which means the row lock in the service is a no-op in this test
 * and the transaction's own write serialisation is doing that half of the
 * work. On a production driver the lock is the mechanism and the index is
 * the backstop. The outcome asserted below is the same either way.
 */
class SubmissionConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to prove this rather than simulate it.');
        }

        $this->database = tempnam(sys_get_temp_dir(), 'fynn_submission').'.sqlite';
        touch($this->database);

        config(['database.connections.concurrency' => [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'concurrency']);

        config([
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.lender.fixture_scenario' => FixtureScenario::Simulated->value,
        ]);

        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);
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

    /**
     * Eight workers released at one instant.
     *
     * Forking alone is not enough here. The children start milliseconds
     * apart, and the first one is usually through the critical section
     * before the second has read anything — so the window never opens and
     * the test passes whether or not the guards exist. Each child waits for
     * a shared wall-clock moment before it begins, which is what makes them
     * actually contend.
     */
    private function inParallel(callable $work): void
    {
        $children = [];
        $startAt = microtime(true) + 0.5;

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('could not fork');
            }

            if ($pid === 0) {
                try {
                    DB::purge('concurrency');
                    DB::reconnect('concurrency');

                    // Connected and warm, then released together.
                    while (microtime(true) < $startAt) {
                        usleep(200);
                    }

                    $work($i);
                    exit(0);
                } catch (\Throwable) {
                    // A worker that loses the race is expected to fail. What
                    // matters is what the database holds afterwards.
                    exit(1);
                }
            }

            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    /**
     * Created the way a customer creates one, so every snapshotted column
     * the application carries is filled by the code that owns it.
     */
    private function application(): LoanApplication
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer']);
        $user->financialProfile->update([
            'monthly_income' => 200000,
            'monthly_expenses' => 40000,
            'savings' => 900000,
        ]);

        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');

        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        return LoanApplication::query()->findOrFail($id);
    }

    public function test_eight_simultaneous_submissions_reach_the_lender_once(): void
    {
        $application = $this->application();

        $this->inParallel(function () use ($application) {
            app(LenderApplicationSubmissionService::class)->submit($application->fresh());
        });

        $attempts = DB::connection('concurrency')
            ->table('application_submissions')
            ->where('loan_application_id', $application->getKey())
            ->get();

        /*
         * One attempt row, and therefore one call to the provider. The
         * provider is only reached after the row is claimed, so counting
         * rows counts external submissions.
         */
        $this->assertCount(
            1,
            $attempts,
            'the same application was submitted to the lender more than once',
        );

        $this->assertSame(1, (int) $attempts[0]->attempt);
        $this->assertNotSame('submitting', $attempts[0]->outcome);
    }

    public function test_the_application_is_left_in_one_coherent_state(): void
    {
        $application = $this->application();

        $this->inParallel(function () use ($application) {
            app(LenderApplicationSubmissionService::class)->submit($application->fresh());
        });

        $fresh = DB::connection('concurrency')
            ->table('loan_applications')
            ->where('id', $application->getKey())
            ->first();

        // Whatever won, the application describes one outcome rather than
        // the wreckage of eight.
        $this->assertNotNull($fresh->status);
        $this->assertNotSame('', $fresh->status);

        $outcomes = DB::connection('concurrency')
            ->table('application_submissions')
            ->where('loan_application_id', $application->getKey())
            ->pluck('outcome');

        $this->assertCount(1, $outcomes->unique());
    }
}
