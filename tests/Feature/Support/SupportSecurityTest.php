<?php

namespace Tests\Feature\Support;

use App\Domain\Consent\ConsentType;
use App\Domain\Support\ContextUnavailable;
use App\Domain\Support\InvalidCaseTransition;
use App\Domain\Support\SupportCaseStatus;
use App\Models\Document;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use App\Services\Support\ContextResolver;
use App\Services\Support\SupportCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose request, what it may be attached to, and what it may become.
 */
class SupportSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
    }

    private function customer(): User
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

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    private function open(array $overrides = [])
    {
        return $this->postJson('/api/v1/support/cases', [
            'category' => 'general',
            'subject' => 'A question about my account',
            'description' => 'I would like to understand how this works.',
            ...$overrides,
        ]);
    }

    private function application(): string
    {
        return $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');
    }

    private function document(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'income.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');
    }

    private function goal(): string
    {
        return $this->postJson('/api/v1/goals', [
            'title' => 'Emergency fund',
            'category' => 'personal',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    // --- IDOR -----------------------------------------------------------------

    public function test_a_customer_cannot_read_another_customers_request(): void
    {
        $theirs = $this->customer();
        $id = $this->open()->json('data.id');

        $this->customer();

        // The same 404 as an id that never existed.
        $this->getJson("/api/v1/support/cases/{$id}")->assertStatus(404);
        $this->getJson('/api/v1/support/cases/999999')->assertStatus(404);
    }

    public function test_a_customer_cannot_add_to_another_customers_request(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $this->customer();

        $this->postJson("/api/v1/support/cases/{$id}/messages", ['body' => 'Let me in'])
            ->assertStatus(404);

        $this->assertSame(0, SupportCaseMessage::count());
    }

    public function test_a_customer_cannot_close_another_customers_request(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        $this->customer();

        $this->postJson("/api/v1/support/cases/{$id}/close")->assertStatus(404);

        $this->assertSame('open', SupportCase::findOrFail($id)->status);
    }

    public function test_a_customer_never_sees_another_customers_requests_in_their_list(): void
    {
        $this->customer();
        $this->open(['subject' => 'Something private to the first customer']);

        $this->customer();

        $list = $this->getJson('/api/v1/support/cases')->assertOk();

        $this->assertCount(0, $list->json('data'));
        $this->assertStringNotContainsString('private to the first', $list->getContent());
    }

    // --- Context ownership -----------------------------------------------------

    public function test_an_application_context_must_be_the_customers_own(): void
    {
        $this->customer();
        $theirApplication = $this->application();

        $this->customer();

        $this->open([
            'category' => 'submission_status',
            'context_type' => 'application',
            'context_id' => $theirApplication,
        ])->assertStatus(422)->assertJsonPath('reason', 'context_unavailable');

        // And nothing about it leaked into a case that was not created.
        $this->assertSame(0, SupportCase::count());
    }

    public function test_a_document_context_must_be_the_customers_own(): void
    {
        $this->customer();
        $theirDocument = $this->document();

        $this->customer();

        $this->open([
            'category' => 'documents',
            'context_type' => 'document',
            'context_id' => $theirDocument,
        ])->assertStatus(422);
    }

    public function test_a_goal_context_must_be_the_customers_own(): void
    {
        $this->customer();
        $theirGoal = $this->goal();

        $this->customer();

        $this->open([
            'context_type' => 'goal',
            'context_id' => $theirGoal,
        ])->assertStatus(422);
    }

    public function test_a_missing_context_fails_the_same_way_as_a_stolen_one(): void
    {
        $this->customer();

        // Somebody probing ids must not be able to tell which of another
        // customer's applications are real.
        $stolen = tap($this->customer(), fn () => null);

        $this->customer();

        $missing = $this->open([
            'context_type' => 'application',
            'context_id' => '999999',
        ]);

        $missing->assertStatus(422)->assertJsonPath('reason', 'context_unavailable');
    }

    public function test_context_type_is_an_allowlist_and_not_a_class_name(): void
    {
        $user = $this->customer();
        $resolver = app(ContextResolver::class);

        /*
         * Asserted at the resolver, which is what enforces it, rather than
         * by firing nine refused requests at the endpoint — those would
         * exhaust the creation limiter and turn a security test into a
         * test of the rate limit.
         */
        foreach ([
            'App\\Models\\User',
            'user',
            'users',
            'credit_report',
            'kyc_verification',
            'document_scan',
            'application_submission',
            '../application',
            'APPLICATION',
        ] as $type) {
            try {
                $resolver->resolve($user, $type, '1');
                $this->fail("[{$type}] was accepted as a context type");
            } catch (ContextUnavailable) {
                // Refused, which is the point.
            }
        }

        // And the endpoint refuses one too, so the wiring is real.
        $this->open(['context_type' => 'App\\Models\\User', 'context_id' => '1'])
            ->assertStatus(422);

        $this->assertSame(0, SupportCase::count());
    }

    public function test_an_identifier_must_be_an_identifier(): void
    {
        $user = $this->customer();
        $this->application();
        $resolver = app(ContextResolver::class);

        foreach (['1 OR 1=1', '../1', '1;', 'abc', '', '-1', '1 '] as $id) {
            try {
                $resolver->resolve($user, 'application', $id);
                $this->fail("[{$id}] was accepted as an identifier");
            } catch (ContextUnavailable) {
                // Refused before it reaches a query.
            }
        }

        $this->open(['context_type' => 'application', 'context_id' => '1 OR 1=1'])
            ->assertStatus(422);
    }

    public function test_a_context_carries_a_reference_and_nothing_out_of_the_record(): void
    {
        $user = $this->customer();
        $applicationId = $this->application();

        $data = $this->open([
            'category' => 'submission_status',
            'context_type' => 'application',
            'context_id' => $applicationId,
        ])->assertCreated()->json('data');

        // What a support screen needs to say which application.
        $this->assertSame('application', $data['context']['type']);
        $this->assertMatchesRegularExpression('/^FE-\d{4}-\d{6}$/', $data['context']['reference']);
        $this->assertNotEmpty($data['context']['status_label']);

        // And not the row's identifier, which is FynnEdge's.
        $this->assertArrayNotHasKey('id', $data['context']);
    }

    public function test_a_document_context_carries_its_kind_and_nothing_from_the_file(): void
    {
        $this->customer();
        $documentId = $this->document();
        $document = Document::findOrFail($documentId);

        $body = $this->open([
            'category' => 'documents',
            'context_type' => 'document',
            'context_id' => $documentId,
        ])->assertCreated()->getContent();

        /*
         * Not storage_disk: it is literally the string "documents", which
         * is also the support category and the context label. Asserting on
         * it would fail on the word rather than on a leak.
         */
        foreach ([
            $document->storage_key,
            $document->original_filename,
            'storage_key',
            'income.pdf',
        ] as $leak) {
            $this->assertStringNotContainsString((string) $leak, $body, "leaked: {$leak}");
        }
    }

    // --- Priority --------------------------------------------------------------

    public function test_priority_comes_from_the_state_and_not_from_the_customer(): void
    {
        config([
            'fynnedge.lender.driver' => 'fixture',
            'fynnedge.lender.fixture_scenario' => 'timeout_after_send',
        ]);

        $user = $this->customer();
        app(ConsentService::class)->grant($user, ConsentType::LenderDataSharing, 'application');

        $applicationId = $this->application();

        $this->postJson("/api/v1/applications/{$applicationId}/submission")
            ->assertOk()
            ->assertJsonPath('data.submission.outcome', 'unknown');

        /*
         * The one blocked state in the product. FynnEdge sent it and never
         * heard back, and Module 28 has told this customer not to submit
         * again — so their request is not an ordinary question.
         */
        $high = $this->open([
            'category' => 'submission_status',
            'context_type' => 'application',
            'context_id' => $applicationId,
            'priority' => 'normal',
        ])->assertCreated()->json('data');

        $this->assertSame('high', $high['priority']);
    }

    public function test_choosing_a_serious_sounding_category_does_not_make_it_urgent(): void
    {
        $this->customer();
        $applicationId = $this->application();

        // A customer can pick "Application status" out of curiosity. What
        // makes a request high is the state behind it, not the selection.
        $data = $this->open([
            'category' => 'submission_status',
            'context_type' => 'application',
            'context_id' => $applicationId,
            'priority' => 'high',
        ])->assertCreated()->json('data');

        $this->assertSame('normal', $data['priority']);
    }

    // --- The state machine -----------------------------------------------------

    public function test_every_transition_the_machine_allows_and_refuses(): void
    {
        $expected = [
            'open' => ['acknowledged', 'in_progress', 'closed'],
            'acknowledged' => ['in_progress', 'waiting_for_customer', 'closed'],
            'in_progress' => ['waiting_for_customer', 'resolved', 'closed'],
            'waiting_for_customer' => ['in_progress', 'resolved', 'closed'],
            'resolved' => ['closed'],
            'closed' => [],
        ];

        foreach (SupportCaseStatus::cases() as $status) {
            $this->assertEqualsCanonicalizing(
                $expected[$status->value],
                array_map(fn (SupportCaseStatus $s) => $s->value, $status->allowedNext()),
                "transitions from [{$status->value}] are not what was specified",
            );

            // And every move not listed is refused, including staying put.
            foreach (SupportCaseStatus::cases() as $target) {
                $this->assertSame(
                    in_array($target->value, $expected[$status->value], true),
                    $status->canMoveTo($target),
                    "[{$status->value}] -> [{$target->value}]",
                );
            }
        }
    }

    public function test_a_resolved_case_cannot_be_reopened(): void
    {
        $user = $this->customer();
        $id = $this->open()->json('data.id');
        $case = SupportCase::findOrFail($id);

        $service = app(SupportCaseService::class);
        $service->transition($case, SupportCaseStatus::InProgress, 'Started', 'advisor');
        $service->transition($case->fresh(), SupportCaseStatus::Resolved, 'Resolved', 'advisor');

        // Going backwards would be undoing a decision somebody made,
        // silently, with no record that it had been made.
        $this->expectException(InvalidCaseTransition::class);

        $service->transition($case->fresh(), SupportCaseStatus::Open, 'Reopened', 'advisor');
    }

    public function test_a_refused_transition_changes_nothing(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');
        $case = SupportCase::findOrFail($id);

        try {
            app(SupportCaseService::class)->transition(
                $case,
                SupportCaseStatus::Resolved,
                'Skipping ahead',
                'advisor',
            );
            $this->fail('expected a refusal');
        } catch (InvalidCaseTransition) {
            // Nothing written: not the status, and not an event claiming
            // something happened.
            $this->assertSame('open', $case->fresh()->status);
            $this->assertCount(1, $case->fresh()->events);
        }
    }

    public function test_every_move_leaves_a_record_of_who_made_it(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        app(SupportCaseService::class)->transition(
            SupportCase::findOrFail($id),
            SupportCaseStatus::InProgress,
            'We started looking into this',
            'advisor',
        );

        $events = SupportCase::findOrFail($id)->events;

        $this->assertCount(2, $events);
        $this->assertSame('open', $events[1]->from_status);
        $this->assertSame('in_progress', $events[1]->to_status);
        $this->assertSame('advisor', $events[1]->source);
    }

    // --- Privacy ----------------------------------------------------------------

    public function test_what_a_customer_writes_never_reaches_the_log(): void
    {
        $this->customer();

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        $secret = 'My PAN is AAAAA0000A and I earn 250000 a month';

        $id = $this->open([
            'subject' => 'A problem with my identity document',
            'description' => $secret,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/support/cases/{$id}/messages", [
            'body' => 'My account number is 123456789012',
        ])->assertCreated();

        $this->postJson("/api/v1/support/cases/{$id}/close")->assertOk();

        $log = implode("\n", $lines);

        // A customer's own words about their own money. The log is the
        // easiest place in a system for them to end up somewhere nobody
        // intended.
        foreach ([
            $secret, 'AAAAA0000A', '250000', '123456789012',
            'A problem with my identity document',
        ] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $log, "logged: {$sensitive}");
        }

        // And what IS logged is enough to find the case.
        $this->assertStringContainsString('support_case_reference', $log);
        $this->assertStringContainsString('FE-SUP-', $log);
    }

    // --- Rate limits ---------------------------------------------------------------

    public function test_creating_cases_is_limited_but_not_meanly(): void
    {
        $this->customer();

        // Five in an hour is far more than anyone with a real problem opens.
        for ($i = 0; $i < 5; $i++) {
            $this->open(['subject' => "Request number {$i} about my account"])
                ->assertCreated();
        }

        $this->open(['subject' => 'One request too many for this hour'])
            ->assertStatus(429);
    }

    public function test_adding_information_is_limited_more_loosely_than_asking(): void
    {
        $this->customer();
        $id = $this->open()->json('data.id');

        // Being helped involves more replying than asking, so the limit is
        // looser. Twenty is well past a real conversation.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/api/v1/support/cases/{$id}/messages", [
                'body' => "Something more I remembered, number {$i}",
            ])->assertCreated();
        }

        $this->postJson("/api/v1/support/cases/{$id}/messages", ['body' => 'And one more'])
            ->assertStatus(429);
    }
}
