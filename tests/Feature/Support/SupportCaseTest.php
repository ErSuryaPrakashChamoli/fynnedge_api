<?php

namespace Tests\Feature\Support;

use App\Domain\Support\SupportCaseStatus;
use App\Domain\Support\SupportCategory;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Asking FynnEdge for help.
 *
 * NO ADVISOR SYSTEM, SUPPORT CRM OR CHAT PROVIDER IS CONNECTED. Every case
 * here is written to FynnEdge's own tables and read back from them; nothing
 * is forwarded anywhere and nothing replies.
 */
class SupportCaseTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function open(array $overrides = [], array $headers = [])
    {
        return $this->withHeaders($headers)->postJson('/api/v1/support/cases', [
            'category' => 'general',
            'subject' => 'A question about my account',
            'description' => 'I would like to understand how this works.',
            ...$overrides,
        ]);
    }

    // --- Creating -----------------------------------------------------------

    public function test_a_customer_can_ask_for_help(): void
    {
        $this->customer();

        $data = $this->open()->assertCreated()->json('data');

        $this->assertSame('open', $data['status']);
        $this->assertSame('normal', $data['priority']);
        $this->assertMatchesRegularExpression('/^FE-SUP-\d{4}-\d{6}$/', $data['reference']);

        // A timeline from the first moment, so the record of what happened
        // starts where the request does.
        $this->assertSame('Request created', $data['timeline'][0]['label']);
    }

    public function test_references_are_sequential_and_unique(): void
    {
        $this->customer();

        $first = $this->open()->json('data.reference');
        $second = $this->open()->json('data.reference');

        $this->assertNotSame($first, $second);
        $this->assertSame(
            ((int) substr($first, -6)) + 1,
            (int) substr($second, -6),
        );
    }

    public function test_the_categories_offered_are_the_ones_that_can_be_chosen(): void
    {
        $this->customer();

        $offered = collect($this->getJson('/api/v1/support/categories')->assertOk()->json('data'));

        $this->assertNotEmpty($offered);

        foreach ($offered as $category) {
            // Labels a customer reads, never an enum name.
            $this->assertNotSame($category['id'], $category['label']);
            $this->assertNotEmpty($category['description']);
        }

        /*
         * Nothing offered that cannot be chosen, and nothing choosable that
         * is not offered. A list that drifts from the allowlist is either a
         * dead button or a category with no way to reach it.
         */
        $this->assertEqualsCanonicalizing(
            SupportCategory::ids(),
            $offered->pluck('id')->all(),
        );

        // And the wiring works, on one of them. Creating thirteen cases to
        // prove that would be testing the rate limiter.
        $this->open(['category' => $offered->first()['id']])->assertCreated();
    }

    public function test_a_category_that_does_not_exist_is_refused(): void
    {
        $this->customer();

        $this->open(['category' => 'billing_dispute'])->assertStatus(422);
        $this->open(['category' => 'general; DROP TABLE support_cases'])->assertStatus(422);
    }

    public function test_the_request_must_actually_say_something(): void
    {
        $this->customer();

        $this->open(['subject' => '', 'description' => ''])->assertStatus(422);
        $this->open(['subject' => 'a'])->assertStatus(422);
        $this->open(['description' => 'help'])->assertStatus(422);
        $this->open(['description' => str_repeat('a', 5000)])->assertStatus(422);
    }

    public function test_a_customer_writing_about_money_is_not_rejected(): void
    {
        $this->customer();

        /*
         * The rule that stops FynnEdge putting a customer's data where it
         * does not belong is the wrong instrument pointed at the customer's
         * own words. Somebody describing their own problem will write
         * figures, and rejecting that would reject the requests most worth
         * reading.
         */
        $data = $this->open([
            'subject' => 'My loan amount is 500000',
            'description' => 'I applied for 500000 over 60 months and my PAN '
                .'AAAAA0000A would not upload. My account 123456789012 is '
                .'the one I use.',
        ])->assertCreated()->json('data');

        $this->assertStringContainsString('500000', $data['subject']);
        $this->assertStringContainsString('AAAAA0000A', $data['description']);
    }

    public function test_control_characters_are_removed_rather_than_refused(): void
    {
        $this->customer();

        $data = $this->open([
            'subject' => "My  application\x00 status\x07",
            'description' => "Line one\n\n\n\n\nLine two\x01 here",
        ])->assertCreated()->json('data');

        $this->assertSame('My application status', $data['subject']);
        $this->assertStringNotContainsString("\x00", $data['description']);
        $this->assertStringNotContainsString("\x01", $data['description']);
        $this->assertStringContainsString('Line one', $data['description']);
        $this->assertStringContainsString('Line two', $data['description']);
    }

    public function test_markup_is_kept_as_text_and_never_becomes_markup(): void
    {
        $this->customer();

        $subject = '<script>alert(1)</script> help';

        $data = $this->open(['subject' => $subject])->assertCreated()->json('data');

        // Stored as the customer typed it — it is their text, and mangling
        // it would lose meaning. What matters is that the response is JSON
        // and the app renders it as a string, so it is never markup.
        $this->assertSame($subject, $data['subject']);
    }

    // --- Idempotency ---------------------------------------------------------

    public function test_tapping_get_help_twice_asks_once(): void
    {
        $this->customer();

        $headers = ['Idempotency-Key' => 'the-same-tap'];

        $first = $this->open([], $headers)->assertCreated()->json('data');
        $second = $this->open([], $headers)->assertOk()->json('data');

        $this->assertSame($first['reference'], $second['reference']);
        $this->assertSame(1, SupportCase::count());
    }

    public function test_two_different_requests_are_two_requests(): void
    {
        $this->customer();

        $this->open([], ['Idempotency-Key' => 'one'])->assertCreated();
        $this->open([], ['Idempotency-Key' => 'two'])->assertCreated();

        $this->assertSame(2, SupportCase::count());
    }

    public function test_one_customers_key_does_not_collide_with_anothers(): void
    {
        $this->customer();
        $this->open([], ['Idempotency-Key' => 'shared'])->assertCreated();

        $this->customer();
        $this->open([], ['Idempotency-Key' => 'shared'])->assertCreated();

        $this->assertSame(2, SupportCase::count());
    }

    // --- What a customer cannot decide --------------------------------------

    public function test_a_customer_cannot_set_their_own_status_priority_or_reference(): void
    {
        $this->customer();

        $data = $this->open([
            'status' => 'resolved',
            'priority' => 'high',
            'reference' => 'FE-SUP-1999-000001',
            'closed_at' => '2020-01-01T00:00:00Z',
        ])->assertCreated()->json('data');

        // Every one ignored. These are FynnEdge's, and a request body that
        // could set them could resolve somebody's own case.
        $this->assertSame('open', $data['status']);
        $this->assertSame('normal', $data['priority']);
        $this->assertNotSame('FE-SUP-1999-000001', $data['reference']);
        $this->assertNull($data['closed_at']);
    }

    public function test_a_customer_cannot_claim_another_customers_case(): void
    {
        $stranger = User::factory()->create();
        $this->customer();

        $reference = $this->open(['user_id' => $stranger->getKey()])
            ->assertCreated()->json('data.reference');

        $case = SupportCase::where('reference', $reference)->firstOrFail();

        $this->assertNotSame($stranger->getKey(), $case->user_id);
    }

    // --- The response --------------------------------------------------------

    public function test_the_response_carries_no_internal_identifiers(): void
    {
        $this->customer();

        $body = $this->open([], ['Idempotency-Key' => 'k'])->getContent();

        foreach (['user_id', 'idempotency', 'author_id', 'context_id'] as $internal) {
            $this->assertStringNotContainsString($internal, $body);
        }
    }

    public function test_support_requires_a_session(): void
    {
        $this->getJson('/api/v1/support/categories')->assertStatus(401);
        $this->getJson('/api/v1/support/cases')->assertStatus(401);
        $this->postJson('/api/v1/support/cases')->assertStatus(401);
        $this->getJson('/api/v1/support/cases/1')->assertStatus(401);
        $this->postJson('/api/v1/support/cases/1/messages')->assertStatus(401);
        $this->postJson('/api/v1/support/cases/1/close')->assertStatus(401);
    }

    // --- Reading -------------------------------------------------------------

    public function test_a_customer_sees_their_own_requests_newest_first(): void
    {
        $this->customer();

        $first = $this->open(['subject' => 'The first thing I asked'])->json('data.reference');
        $second = $this->open(['subject' => 'The second thing I asked'])->json('data.reference');

        $list = $this->getJson('/api/v1/support/cases')->assertOk();

        $this->assertSame(
            [$second, $first],
            array_column($list->json('data'), 'reference'),
        );
        $this->assertSame(2, $list->json('meta.open_count'));
    }

    public function test_the_list_does_not_carry_every_word_of_every_request(): void
    {
        $this->customer();
        $this->open(['description' => 'A long account of my particular problem.']);

        $list = $this->getJson('/api/v1/support/cases')->assertOk();

        // The description belongs on the detail view. A list of requests
        // does not need all of it.
        $this->assertArrayNotHasKey('description', $list->json('data.0'));

        $id = $list->json('data.0.id');
        $this->assertStringContainsString(
            'particular problem',
            $this->getJson("/api/v1/support/cases/{$id}")->assertOk()->json('data.description'),
        );
    }

    // --- Adding information ---------------------------------------------------

    public function test_a_customer_can_add_information(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $data = $this->postJson("/api/v1/support/cases/{$id}/messages", [
            'body' => 'I forgot to mention the date it happened.',
        ])->assertCreated()->json('data');

        $this->assertCount(1, $data['messages']);
        $this->assertSame('you', $data['messages'][0]['author']);
        $this->assertStringContainsString('forgot to mention', $data['messages'][0]['body']);
    }

    public function test_a_customer_cannot_author_a_message_from_fynnedge(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $this->postJson("/api/v1/support/cases/{$id}/messages", [
            'body' => 'We have approved your loan.',
            'author_type' => 'advisor',
            'author_id' => 999,
        ])->assertCreated();

        /*
         * The one field that would let a customer put words in FynnEdge's
         * mouth on their own case, and then quote them.
         */
        $this->assertSame(
            'you',
            $this->getJson("/api/v1/support/cases/{$id}")->json('data.messages.0.author'),
        );
    }

    public function test_nothing_can_be_added_to_a_closed_request(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $this->postJson("/api/v1/support/cases/{$id}/close")->assertOk();

        $this->postJson("/api/v1/support/cases/{$id}/messages", ['body' => 'One more thing'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'case_closed');
    }

    // --- Closing ---------------------------------------------------------------

    public function test_a_customer_can_close_their_own_request(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $data = $this->postJson("/api/v1/support/cases/{$id}/close")->assertOk()->json('data');

        $this->assertSame('closed', $data['status']);
        $this->assertFalse($data['is_open']);
        $this->assertNotNull($data['closed_at']);

        // Who closed it, and why, so `closed` does not mean three things.
        $this->assertSame(
            'customer_closed',
            SupportCase::findOrFail($id)->closed_reason,
        );
        $this->assertSame('You closed this request', end($data['timeline'])['label']);
    }

    public function test_closing_twice_is_refused_rather_than_silently_accepted(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $this->postJson("/api/v1/support/cases/{$id}/close")->assertOk();

        $this->postJson("/api/v1/support/cases/{$id}/close")
            ->assertStatus(409)
            ->assertJsonPath('reason', 'case_closed');
    }

    public function test_a_closed_request_stays_closed(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');
        $this->postJson("/api/v1/support/cases/{$id}/close")->assertOk();

        $case = SupportCase::findOrFail($id);

        $this->assertTrue($case->status()->isTerminal());
        $this->assertSame([], $case->status()->allowedNext());
        $this->assertSame(SupportCaseStatus::Closed, $case->status());
    }
}
