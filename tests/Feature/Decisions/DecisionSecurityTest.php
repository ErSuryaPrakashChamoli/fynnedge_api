<?php

namespace Tests\Feature\Decisions;

use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\RefusedDecisionData;
use App\Models\CatalogueProduct;
use App\Models\DecisionContext;
use App\Models\User;
use App\Services\Catalogue\CatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Whose history, what it may contain, and whether it survives the present.
 */
class DecisionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function customer(int $income = 150000): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => $income,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function simulate(array $overrides = [])
    {
        return $this->postJson('/api/v1/fynn-twin/simulate', [
            'type' => 'loan',
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
            ...$overrides,
        ]);
    }

    // --- IDOR --------------------------------------------------------------------

    public function test_a_customer_never_sees_another_customers_decisions(): void
    {
        $this->customer();
        $this->simulate()->assertOk();
        $theirs = $this->getJson('/api/v1/decisions')->json('data.0.id');

        $this->customer();

        $this->assertSame([], $this->getJson('/api/v1/decisions')->assertOk()->json('data'));

        // The same 404 as an id that never existed.
        $this->getJson("/api/v1/decisions/{$theirs}")->assertStatus(404);
        $this->getJson('/api/v1/decisions/d999999')->assertStatus(404);
    }

    public function test_an_application_decision_is_not_reachable_by_another_customer(): void
    {
        $this->customer();
        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated();
        $theirs = $this->getJson('/api/v1/decisions')->json('data.0.id');

        $this->customer();

        $this->getJson("/api/v1/decisions/{$theirs}")->assertStatus(404);
    }

    public function test_decisions_require_a_session(): void
    {
        $this->getJson('/api/v1/decisions')->assertStatus(401);
        $this->getJson('/api/v1/decisions/d1')->assertStatus(401);
    }

    // --- Historical integrity -----------------------------------------------------

    public function test_a_catalogue_change_does_not_rewrite_what_was_shown(): void
    {
        $this->customer();
        $this->simulate()->assertOk();

        $before = $this->getJson('/api/v1/decisions/'
            .$this->getJson('/api/v1/decisions')->json('data.0.id'))
            ->assertOk()->json('data');

        // The catalogue moves underneath them.
        DecisionContext::query()->exists();
        $catalogue = app(CatalogueService::class);

        $product = CatalogueProduct::where('public_id', 'prod_bl_b')->first();

        if ($product !== null) {
            $product->versions()->latest('id')->first()?->update(['interest_rate' => 22.5]);
        }

        $after = $this->getJson('/api/v1/decisions/'.$before['id'])->assertOk()->json('data');

        /*
         * The explanation still says what the customer was shown. A rate
         * that changes tomorrow does not change what they saw today, and
         * an explanation recomputed from today's catalogue would be a
         * confident account of something that never happened.
         */
        $this->assertSame($before['explanation'], $after['explanation']);
        $this->assertStringNotContainsString('22.5', json_encode($after));
    }

    public function test_a_changed_profile_does_not_rewrite_an_earlier_decision(): void
    {
        $this->customer();
        $this->simulate()->assertOk();

        $id = $this->getJson('/api/v1/decisions')->json('data.0.id');
        $before = $this->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data.explanation');

        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => 40000, 'monthly_expenses' => 35000,
            'existing_emi' => 30000, 'savings' => 0,
            'employment_type' => 'salaried',
        ])->assertOk();

        $after = $this->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data.explanation');

        // What was true then stays what was true then.
        $this->assertSame($before, $after);

        // Asserted on the structure, not on encoded JSON — json_encode
        // escapes the rupee sign, so a string search would be looking for
        // something that is never in the output.
        $income = collect($after)
            ->pluck('factors')
            ->flatten(1)
            ->firstWhere('label', 'Monthly income');

        $this->assertSame('₹1,50,000', $income['value']);
        $this->assertSame('As it was at the time.', $income['note']);
    }

    public function test_a_cancelled_application_keeps_its_history(): void
    {
        $this->customer();

        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $before = count($this->getJson('/api/v1/decisions')->json('data'));

        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();

        // Cancelling is something that happened afterwards. It does not
        // unmake the decision to start.
        $this->assertSame($before, count($this->getJson('/api/v1/decisions')->json('data')));
    }

    // --- What a decision record may hold --------------------------------------------

    /** @return iterable<string, array{string, mixed}> */
    public static function refusedData(): iterable
    {
        yield 'a field nobody allowed' => ['pan', 'AAAAA0000A'];
        yield 'a document number' => ['document_number', '1234'];
        yield 'a storage key' => ['storage_key', 'documents/u1/x.pdf'];
        yield 'a provider payload' => ['provider_payload', ['a' => 'b']];
        yield 'an auth token' => ['token', 'abc'];
        yield 'an AI conversation' => ['conversation', 'hello'];
    }

    #[DataProvider('refusedData')]
    public function test_a_decision_record_refuses_what_it_must_not_hold(string $key, mixed $value): void
    {
        $this->expectException(RefusedDecisionData::class);

        DecisionSnapshot::of([$key => $value]);
    }

    public function test_an_allowed_field_carrying_a_forbidden_value_is_still_refused(): void
    {
        /*
         * The subtler leak: the right key with the wrong thing in it. A
         * scenario name is allowed; a scenario name containing a PAN is the
         * same disclosure as a field called `pan`.
         */
        $this->expectException(RefusedDecisionData::class);

        DecisionSnapshot::of(['scenario' => 'loan for AAAAA0000A']);
    }

    public function test_the_figures_a_decision_does_hold_are_accepted(): void
    {
        // A boundary that refuses everything is not a boundary.
        $snapshot = DecisionSnapshot::of([
            'amount' => 1000000,
            'tenure_months' => 60,
            'interest_rate' => 13.25,
            'emi' => 22881,
            'monthly_income' => 150000,
            'product_codes' => ['prod_bl_a', 'prod_bl_b'],
        ]);

        $this->assertSame(1000000, $snapshot->get('amount'));
        $this->assertSame(['prod_bl_a', 'prod_bl_b'], $snapshot->get('product_codes'));
    }

    public function test_nothing_sensitive_reaches_the_decision_api(): void
    {
        $this->customer();
        $this->simulate()->assertOk();
        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated();

        $body = $this->getJson('/api/v1/decisions')->getContent();

        foreach ($this->getJson('/api/v1/decisions')->json('data') as $entry) {
            $body .= $this->getJson('/api/v1/decisions/'.$entry['id'])->getContent();
        }

        foreach ([
            'user_id', 'idempotency', 'storage_key', 'file_digest',
            'provider_reference', 'payload', 'AAAAA0000A', 'aadhaar',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "leaked: {$leak}");
        }
    }

    public function test_what_a_customer_explored_never_reaches_the_log(): void
    {
        $lines = [];
        Log::listen(function ($m) use (&$lines) {
            $lines[] = $m->message.' '.json_encode($m->context);
        });

        $this->customer(275000);
        $this->simulate(['amount' => 3750000])->assertOk();

        $log = implode("\n", $lines);

        // What somebody is thinking about borrowing is their business.
        foreach (['3750000', '275000', 'idempotency'] as $figure) {
            $this->assertStringNotContainsString($figure, $log, "logged: {$figure}");
        }
    }

    // --- No unsupported claims -------------------------------------------------------

    public function test_no_explanation_makes_a_claim_nothing_supports(): void
    {
        $this->customer();
        $this->simulate()->assertOk();
        $this->postJson('/api/v1/compare', [
            'product_ids' => ['prod_bl_b', 'prod_bl_a'],
        ])->assertOk();
        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b', 'amount' => 1000000, 'tenure_months' => 60,
        ])->assertCreated();

        $whole = '';
        foreach ($this->getJson('/api/v1/decisions')->json('data') as $entry) {
            $whole .= $this->getJson('/api/v1/decisions/'.$entry['id'])->getContent();
        }
        $whole = strtolower($whole);

        foreach ([
            'guaranteed', 'you are approved', 'likely to be approved',
            'approval probability', 'cheapest', 'is best', 'best option',
            'we recommend', 'you should', 'will save you', 'affordable for you',
            'eligible', 'safe to borrow',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $whole, "claimed: {$claim}");
        }
    }

    public function test_an_affordability_explanation_says_what_it_did_not_check(): void
    {
        $this->customer();
        $this->simulate()->assertOk();

        $id = $this->getJson('/api/v1/decisions')->json('data.0.id');
        $detail = $this->getJson("/api/v1/decisions/{$id}")->assertOk()->json('data');

        $affordability = collect($detail['explanation'])
            ->firstWhere('title', 'What that would do to your monthly commitments');

        $this->assertNotNull($affordability);

        // A gap where approval would be is a gap a customer reads as a
        // pass. It is said out loud instead.
        $this->assertNotEmpty($affordability['not_assessed']);
        $this->assertStringContainsString(
            'lender',
            strtolower(implode(' ', $affordability['not_assessed'])),
        );

        // And the reference is FynnEdge's, stated as FynnEdge's.
        $this->assertStringContainsString(
            "FynnEdge's own reference",
            $affordability['rule'],
        );
    }
}
