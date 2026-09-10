<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Consent\ConsentType;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What reading the intelligence layer costs.
 *
 * The rule these pin is not a number but a shape: the cost of describing a
 * customer must not grow with how much they have. A customer with nine
 * applications is FynnEdge's most engaged, and they are exactly the person
 * for whom a per-application round trip turns into a slow home screen.
 *
 * Three N+1s were found here by measurement rather than by reading code.
 * `LoanApplication::latestSubmission()` issued a query on every call even
 * when the submissions were already loaded beside it, and both Home and the
 * FynnAI context loaded applications without them — so every rule that
 * asked an application what became of its last attempt paid for a round
 * trip, once per application, several times per request.
 */
class IntelligenceQueryCostTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Generous. The point is that the number does not grow with the
     * customer's data, not that it hits a particular value — a bound tight
     * enough to break on an extra `select` would be a test nobody could
     * change a query without arguing with.
     */
    private const CEILING = 12;

    private function customerWith(int $goals, int $applications): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);
        app(ConsentService::class)->grant($user, ConsentType::AiMemory, 'privacy_screen');

        for ($i = 0; $i < $goals; $i++) {
            $this->postJson('/api/v1/goals', [
                'title' => "Goal {$i}",
                'category' => 'personal',
                'target_amount' => 100000,
                'target_date' => now()->addYear()->toDateString(),
            ])->assertCreated();
        }

        for ($i = 0; $i < $applications; $i++) {
            $this->postJson('/api/v1/applications', [
                'product_id' => 'prod_bl_b',
                'amount' => 1000000,
                'tenure_months' => 60,
            ])->assertCreated();
        }

        return $user;
    }

    private function queriesFor(string $path): int
    {
        $count = 0;

        DB::listen(function () use (&$count) {
            $count++;
        });

        $this->getJson($path)->assertOk();

        return $count;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readPaths(): iterable
    {
        yield 'home' => ['/api/v1/home'];
        yield 'intelligence' => ['/api/v1/intelligence'];
        yield 'timeline' => ['/api/v1/intelligence/timeline'];
        yield 'the FynnAI context' => ['/api/v1/ai/context'];
        yield 'decision history' => ['/api/v1/decisions'];
    }

    #[DataProvider('readPaths')]
    public function test_a_read_costs_the_same_however_much_the_customer_has(string $path): void
    {
        $this->customerWith(goals: 2, applications: 2);
        $small = $this->queriesFor($path);

        $this->customerWith(goals: 8, applications: 9);
        $large = $this->queriesFor($path);

        $this->assertSame(
            $small,
            $large,
            "[{$path}] cost {$small} queries for a small customer and {$large} "
            .'for a larger one — that is an N+1.',
        );

        $this->assertLessThanOrEqual(
            self::CEILING,
            $large,
            "[{$path}] took {$large} queries to describe one customer.",
        );
    }

    /**
     * Decision detail, which resolves one entry out of the history.
     *
     * Separate because it needs an id, and the id has to come from a
     * history that exists.
     */
    public function test_decision_detail_costs_the_same_however_much_the_customer_has(): void
    {
        $this->customerWith(goals: 2, applications: 2);
        $small = $this->detailCost();

        $this->customerWith(goals: 8, applications: 9);
        $large = $this->detailCost();

        $this->assertSame(
            $small,
            $large,
            "decision detail cost {$small} queries for a small customer and "
            ."{$large} for a larger one — that is an N+1.",
        );
        $this->assertLessThanOrEqual(self::CEILING, $large);
    }

    private function detailCost(): int
    {
        $id = $this->getJson('/api/v1/decisions')->assertOk()->json('data.0.id');

        return $this->queriesFor('/api/v1/decisions/'.$id);
    }

    /**
     * And reading a decision must never read a document.
     *
     * A decision explains figures. Nothing about it needs a file, and a
     * read path that touched one would re-hash it — the exact cost the
     * ContentDigest work removed.
     */
    public function test_reading_a_decision_touches_no_document(): void
    {
        $this->customerWith(goals: 1, applications: 1);

        $tables = [];
        DB::listen(function ($q) use (&$tables) {
            if (preg_match('/from\s+"?(\w+)"?/i', $q->sql, $m)) {
                $tables[] = strtolower($m[1]);
            }
        });

        $id = $this->getJson('/api/v1/decisions')->assertOk()->json('data.0.id');
        $this->getJson('/api/v1/decisions/'.$id)->assertOk();

        foreach (['documents', 'document_scans', 'kyc_verifications'] as $vault) {
            $this->assertNotContains(
                $vault,
                $tables,
                "reading a decision queried [{$vault}]",
            );
        }
    }

    public function test_the_state_is_read_once_per_request_not_per_rule(): void
    {
        $this->customerWith(goals: 3, applications: 3);

        /*
         * Home asks for the attention rules and the next action, and the
         * intelligence endpoint asks for the insights and the next action.
         * Both hand the same state to both rule sets rather than building
         * it twice — which is about agreement as much as cost, since two
         * reads of the same customer can differ.
         */
        $home = $this->queriesFor('/api/v1/home');
        $intelligence = $this->queriesFor('/api/v1/intelligence');

        $this->assertLessThanOrEqual(self::CEILING, $home);
        $this->assertLessThanOrEqual(self::CEILING, $intelligence);
    }
}
