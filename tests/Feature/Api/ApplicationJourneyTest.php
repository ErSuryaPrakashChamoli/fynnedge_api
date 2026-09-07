<?php

namespace Tests\Feature\Api;

use App\Domain\Application\ApplicationStatus;
use App\Models\LoanApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->financialProfile->update(array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 180000,
        ], $financials));
        $user->customerProfile->update(['age' => 34]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function start(array $body = [], array $headers = []): TestResponse
    {
        return $this->postJson('/api/v1/applications', array_merge([
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ], $body), $headers);
    }

    private function startedId(array $body = []): string
    {
        return $this->start($body)->assertCreated()->json('data.id');
    }

    private function submit(string $id, array $body = ['acknowledged' => true]): TestResponse
    {
        return $this->postJson("/api/v1/applications/{$id}/submit", $body);
    }

    // --- Creation -----------------------------------------------------------

    public function test_it_starts_an_application_from_a_product(): void
    {
        $this->customer();

        $response = $this->start()->assertCreated();

        $response
            ->assertJsonPath('data.product_id', 'prod_bl_b')
            ->assertJsonPath('data.lender', 'United Credit Bank')
            ->assertJsonPath('data.amount', 1000000)
            ->assertJsonPath('data.tenure_months', 60)
            ->assertJsonPath('data.status', 'started')
            ->assertJsonPath('data.status_label', 'Started')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.is_simulated', false);

        $this->assertMatchesRegularExpression(
            '/^FE-\d{4}-\d{6}$/',
            $response->json('data.reference'),
        );
    }

    public function test_it_prices_the_application_with_the_financial_engine(): void
    {
        $this->customer();

        $data = $this->start()->assertCreated()->json('data');

        // 10L at 12.4% over 60 months, and the projected ratio the customer
        // was shown on the match.
        $this->assertSame(22447.11, $data['emi']);
        $this->assertSame(42.45, $data['projected_emi_ratio']);
        $this->assertSame(10000, $data['processing_fee']);
        $this->assertSame(12.4, $data['interest_rate']);
    }

    public function test_it_records_who_did_what(): void
    {
        $this->customer();

        $events = $this->start()->json('data.events');

        $this->assertCount(1, $events);
        $this->assertSame('Started', $events[0]['label']);
        // FynnEdge speaking about its own action, not a provider.
        $this->assertSame('fynnedge', $events[0]['source']);
    }

    public function test_an_unknown_product_cannot_be_applied_for(): void
    {
        $this->customer();

        $this->start(['product_id' => 'prod_nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That product is no longer available.');
    }

    public function test_it_validates_what_it_needs(): void
    {
        $this->customer();

        $this->postJson('/api/v1/applications', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id', 'amount', 'tenure_months']);

        $this->start(['amount' => -1])->assertStatus(422);
        $this->start(['tenure_months' => 0])->assertStatus(422);
    }

    // --- Exact preservation -------------------------------------------------

    public function test_the_amount_and_tenure_are_stored_exactly_as_asked(): void
    {
        $this->customer();

        // Deliberately awkward: not a round number, and a tenure this
        // product does not offer.
        $data = $this->start([
            'amount' => 1234567.89,
            'tenure_months' => 7,
        ])->assertCreated()->json('data');

        $this->assertSame(1234567.89, $data['amount']);
        $this->assertSame(7, $data['tenure_months']);

        // And it survives the round trip to the detail endpoint.
        $detail = $this->getJson('/api/v1/applications/'.$data['id'])
            ->assertOk()->json('data');

        $this->assertSame(1234567.89, $detail['amount']);
        $this->assertSame(7, $detail['tenure_months']);
        $this->assertSame('prod_bl_b', $detail['product_id']);
    }

    public function test_submission_does_not_change_the_figures(): void
    {
        $this->customer();
        $id = $this->startedId(['amount' => 1234567.89, 'tenure_months' => 7]);

        $before = $this->getJson('/api/v1/applications/'.$id)->json('data');
        $after = $this->submit($id)->assertOk()->json('data');

        foreach (['amount', 'tenure_months', 'product_id', 'emi', 'interest_rate'] as $field) {
            $this->assertSame(
                $before[$field],
                $after[$field],
                "submission changed {$field}",
            );
        }
    }

    public function test_editing_a_draft_reprices_through_the_engine(): void
    {
        $this->customer();
        $id = $this->startedId();

        $data = $this->putJson('/api/v1/applications/'.$id, [
            'amount' => 2000000,
            'tenure_months' => 84,
        ])->assertOk()->json('data');

        $this->assertSame(2000000, $data['amount']);
        $this->assertSame(84, $data['tenure_months']);
        // 20L at 12.4% over 84 months.
        $this->assertSame(35734.72, $data['emi']);
    }

    public function test_a_submitted_application_can_no_longer_be_edited(): void
    {
        $this->customer();
        $id = $this->startedId();
        $this->submit($id)->assertOk();

        $this->putJson('/api/v1/applications/'.$id, ['amount' => 500000])
            ->assertStatus(409);

        $this->assertSame(
            1000000,
            $this->getJson('/api/v1/applications/'.$id)->json('data.amount'),
        );
    }

    // --- Submission ---------------------------------------------------------

    public function test_submission_requires_the_acknowledgement(): void
    {
        $this->customer();
        $id = $this->startedId();

        $this->submit($id, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('acknowledged');

        $this->submit($id, ['acknowledged' => false])->assertStatus(422);

        // And nothing moved.
        $this->assertSame(
            'started',
            $this->getJson('/api/v1/applications/'.$id)->json('data.status'),
        );
    }

    public function test_a_submitted_application_says_nothing_was_actually_sent(): void
    {
        $this->customer();
        $data = $this->submit($this->startedId())->assertOk()->json('data');

        $this->assertSame('submitted', $data['status']);
        $this->assertNotNull($data['submitted_at']);
        $this->assertNotNull($data['acknowledged_at']);
        $this->assertSame('v1', $data['acknowledgement_version']);

        // The mock gateway took it, and says so.
        $this->assertTrue($data['is_simulated']);
        $this->assertSame('mock', $data['gateway']);
        $this->assertNull($data['provider_reference']);

        $submitted = collect($data['events'])->firstWhere('label', 'Submitted');
        $this->assertSame('simulated', $submitted['source']);
        $this->assertStringContainsString('No provider has received', $submitted['detail']);
    }

    public function test_the_mock_gateway_never_invents_a_provider_decision(): void
    {
        $this->customer();
        $data = $this->submit($this->startedId())->assertOk()->json('data');

        // Submitted, and no further. Under review, approved, declined and
        // disbursed are a provider's to report.
        $this->assertSame('submitted', $data['status']);
        $this->assertFalse(
            ApplicationStatus::from($data['status'])->isProviderDecision()
        );

        foreach ($data['events'] as $event) {
            $this->assertNotSame('provider', $event['source']);
        }
    }

    public function test_a_started_application_is_never_reported_as_sent(): void
    {
        $this->customer();
        $data = $this->start()->json('data');

        $this->assertNull($data['submitted_at']);
        $this->assertNull($data['gateway']);
        $this->assertFalse($data['is_simulated']);
        $this->assertNull($data['acknowledged_at']);
    }

    // --- Idempotency --------------------------------------------------------

    public function test_the_same_creation_key_makes_one_application(): void
    {
        $this->customer();

        $first = $this->start([], ['Idempotency-Key' => 'tap-1'])->assertCreated();
        $second = $this->start([], ['Idempotency-Key' => 'tap-1'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));
        $this->assertSame(1, LoanApplication::count());
    }

    public function test_different_keys_make_different_applications(): void
    {
        $this->customer();

        $this->start([], ['Idempotency-Key' => 'tap-1'])->assertCreated();
        $this->start([], ['Idempotency-Key' => 'tap-2'])->assertCreated();

        $this->assertSame(2, LoanApplication::count());
    }

    public function test_a_key_belongs_to_the_customer_who_used_it(): void
    {
        $this->customer();
        $this->start([], ['Idempotency-Key' => 'shared'])->assertCreated();

        // A second customer reusing the same key gets their own application,
        // never a peek at the first one's.
        $other = $this->customer();
        $second = $this->start([], ['Idempotency-Key' => 'shared'])->assertCreated();

        $this->assertSame(2, LoanApplication::count());
        $this->assertSame(
            $other->id,
            LoanApplication::find($second->json('data.id'))->user_id,
        );
    }

    public function test_submitting_twice_submits_once(): void
    {
        $this->customer();
        $id = $this->startedId();

        $first = $this->submit($id)->assertOk()->json('data');
        $second = $this->submit($id)->assertOk()->json('data');

        $this->assertSame($first['submitted_at'], $second['submitted_at']);
        $this->assertSame($first['events'], $second['events']);

        $submissions = collect($second['events'])
            ->where('label', 'Submitted')
            ->count();
        $this->assertSame(1, $submissions);
    }

    public function test_rapid_repeated_submits_produce_one_submission(): void
    {
        $this->customer();
        $id = $this->startedId();

        for ($i = 0; $i < 5; $i++) {
            $this->submit($id)->assertOk();
        }

        $application = LoanApplication::find($id);
        $this->assertSame(1, $application->events()->where('label', 'Submitted')->count());
        $this->assertSame('submitted', $application->status);
    }

    // --- Cancellation -------------------------------------------------------

    public function test_a_draft_can_be_cancelled(): void
    {
        $this->customer();
        $id = $this->startedId();

        $data = $this->postJson("/api/v1/applications/{$id}/cancel")
            ->assertOk()->json('data');

        $this->assertSame('cancelled', $data['status']);
        $this->assertNotNull($data['cancelled_at']);
        $this->assertFalse($data['can_submit']);
        $this->assertFalse($data['can_cancel']);
    }

    public function test_a_submitted_application_can_still_be_cancelled(): void
    {
        $this->customer();
        $id = $this->startedId();
        $this->submit($id)->assertOk();

        $this->postJson("/api/v1/applications/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_cancelled_application_cannot_be_submitted(): void
    {
        $this->customer();
        $id = $this->startedId();
        $this->postJson("/api/v1/applications/{$id}/cancel")->assertOk();

        $this->submit($id)->assertStatus(409);
        $this->postJson("/api/v1/applications/{$id}/cancel")->assertStatus(409);
    }

    // --- Listing ------------------------------------------------------------

    public function test_the_list_is_the_customers_own_and_newest_first(): void
    {
        $this->customer();

        $ids = [];
        foreach ([1000000, 1100000, 1200000] as $i => $amount) {
            $ids[] = $this->startedId(['amount' => $amount]);
        }

        $listed = collect($this->getJson('/api/v1/applications')->assertOk()->json('data'))
            ->pluck('id')
            ->all();

        $this->assertSame(array_reverse($ids), $listed);

        // Deterministic: the same order on a second read.
        $this->assertSame(
            $listed,
            collect($this->getJson('/api/v1/applications')->json('data'))->pluck('id')->all(),
        );
    }

    public function test_a_customer_with_no_applications_gets_an_empty_list(): void
    {
        $this->customer();

        $this->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // --- Ownership ----------------------------------------------------------

    public function test_another_customers_application_is_a_plain_404(): void
    {
        $victim = $this->customer();
        $theirs = $this->startedId();

        $this->customer();

        $missing = $this->getJson('/api/v1/applications/999999');
        $notMine = $this->getJson('/api/v1/applications/'.$theirs);

        // Indistinguishable: same status, same message. (The debug trace
        // that PHPUnit's environment adds is not part of the response a
        // client sees.)
        $notMine->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->json('message'), $notMine->json('message'));
        $this->assertSame(
            'That application could not be found.',
            $notMine->json('message'),
        );

        $this->assertNotNull(LoanApplication::find($theirs));
        $this->assertSame($victim->id, LoanApplication::find($theirs)->user_id);
    }

    public function test_another_customers_application_cannot_be_changed(): void
    {
        $this->customer();
        $theirs = $this->startedId();

        $this->customer();

        $this->putJson('/api/v1/applications/'.$theirs, ['amount' => 1])
            ->assertNotFound();
        $this->postJson('/api/v1/applications/'.$theirs.'/submit', ['acknowledged' => true])
            ->assertNotFound();
        $this->postJson('/api/v1/applications/'.$theirs.'/cancel')
            ->assertNotFound();

        $untouched = LoanApplication::find($theirs);
        $this->assertSame('started', $untouched->status);
        $this->assertSame('1000000.00', $untouched->amount);
    }

    public function test_a_user_id_in_the_payload_never_establishes_ownership(): void
    {
        $victim = $this->customer();
        $me = $this->customer();

        $id = $this->start([
            'user_id' => $victim->id,
            'customer_id' => $victim->id,
        ])->assertCreated()->json('data.id');

        $this->assertSame($me->id, LoanApplication::find($id)->user_id);
        $this->assertSame(0, $victim->applications()->count());
    }

    public function test_the_whole_journey_requires_a_session(): void
    {
        $this->customer();
        $id = $this->startedId();
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/applications')->assertStatus(401);
        $this->getJson('/api/v1/applications/'.$id)->assertStatus(401);
        $this->postJson('/api/v1/applications', [])->assertStatus(401);
        $this->putJson('/api/v1/applications/'.$id, [])->assertStatus(401);
        $this->postJson('/api/v1/applications/'.$id.'/submit', [])->assertStatus(401);
        $this->postJson('/api/v1/applications/'.$id.'/cancel')->assertStatus(401);
    }

    // --- Serialization ------------------------------------------------------

    public function test_the_payload_carries_everything_the_screens_need(): void
    {
        $this->customer();
        $this->submit($this->startedId());

        $this->getJson('/api/v1/applications')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'reference',
                    'product_id', 'product_name', 'lender', 'product_category',
                    'amount', 'tenure_months', 'interest_rate',
                    'emi', 'total_interest', 'total_payable', 'processing_fee',
                    'projected_emi_ratio', 'match_category',
                    'status', 'status_label', 'next_action',
                    'can_submit', 'can_cancel', 'is_editable',
                    'is_simulated', 'gateway', 'provider_reference',
                    'acknowledged_at', 'acknowledgement_version', 'acknowledgements',
                    'submitted_at', 'cancelled_at', 'created_at', 'updated_at',
                    'events' => [['label', 'detail', 'source', 'at']],
                ]],
            ]);
    }

    public function test_the_acknowledgements_are_stated_not_implied(): void
    {
        $this->customer();
        $data = $this->start()->json('data');

        $this->assertCount(2, $data['acknowledgements']);
        $text = implode(' ', $data['acknowledgements']);

        $this->assertStringContainsString('accurate', $text);
        $this->assertStringContainsString('provider decides', $text);

        // Nothing here claims a bureau or credit consent: there is no such
        // integration, so no such consent may be recorded.
        foreach (['credit report', 'bureau', 'CIBIL', 'credit check'] as $absent) {
            $this->assertStringNotContainsStringIgnoringCase($absent, $text);
        }
    }
}
