<?php

namespace Tests\Feature\Api;

use App\Domain\Consent\ConsentType;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ConsentTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function grant(string $type, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/consents', array_merge([
            'type' => $type,
        ], $extra));
    }

    private function state(string $type): array
    {
        return collect($this->getJson('/api/v1/consents')->assertOk()->json('data.consents'))
            ->firstWhere('type', $type);
    }

    // --- The catalogue ------------------------------------------------------

    public function test_it_lists_every_consent_with_its_standing(): void
    {
        $this->customer();

        $data = $this->getJson('/api/v1/consents')->assertOk()->json('data');

        $this->assertCount(count(ConsentType::cases()), $data['consents']);

        foreach ($data['consents'] as $consent) {
            $this->assertArrayHasKey('label', $consent);
            $this->assertArrayHasKey('description', $consent);
            $this->assertArrayHasKey('is_required', $consent);
            $this->assertArrayHasKey('is_available', $consent);
            $this->assertFalse($consent['is_granted'], 'nothing is granted by default');
        }
    }

    public function test_nothing_is_consented_to_on_the_customers_behalf(): void
    {
        $user = $this->customer();

        // Creating an account grants nothing. Consent is something the
        // customer does, not something a signup implies.
        $this->assertSame(0, $user->consents()->count());

        $outstanding = $this->getJson('/api/v1/consents')
            ->json('data.outstanding_required');

        $this->assertSame(['service_terms', 'privacy_notice'], $outstanding);
    }

    public function test_required_and_optional_are_distinguished(): void
    {
        $this->customer();

        $this->assertTrue($this->state('service_terms')['is_required']);
        $this->assertTrue($this->state('privacy_notice')['is_required']);
        $this->assertFalse($this->state('ai_memory')['is_required']);
    }

    // --- No bureau consent --------------------------------------------------

    public function test_the_bureau_consent_is_not_available(): void
    {
        $this->customer();
        $bureau = $this->state('credit_bureau_check');

        $this->assertFalse($bureau['is_available']);
        $this->assertNull($bureau['current_version']);
        $this->assertNotNull($bureau['unavailable_reason']);
        $this->assertStringContainsString('no bureau connection', $bureau['description']);
    }

    public function test_an_unavailable_consent_cannot_be_granted(): void
    {
        $this->customer();

        foreach ([
            'credit_bureau_check',
            'lender_data_sharing',
            'ai_training',
            'marketing_messages',
        ] as $type) {
            $this->grant($type)
                ->assertStatus(422)
                ->assertJsonPath('message', 'That permission is not available yet. '
                    .'FynnEdge will ask you when the feature behind it exists.');

            $this->assertFalse($this->state($type)['is_granted']);
        }

        $this->assertSame(0, Consent::count());
    }

    public function test_no_available_consent_claims_a_capability_that_is_missing(): void
    {
        $this->customer();

        foreach ($this->getJson('/api/v1/consents')->json('data.consents') as $consent) {
            if (! $consent['is_available']) {
                continue;
            }

            // A grantable consent must not describe a bureau pull, a lender
            // submission or a marketing send: none of those exist.
            $text = mb_strtolower($consent['label'].' '.$consent['description']);

            foreach (['credit report', 'credit check', 'bureau', 'cibil'] as $forbidden) {
                if (str_contains($text, $forbidden)) {
                    $this->fail(
                        "Grantable consent {$consent['type']} refers to "
                        ."'{$forbidden}', which FynnEdge cannot do."
                    );
                }
            }
        }

        $this->assertTrue(true);
    }

    // --- Granting -----------------------------------------------------------

    public function test_granting_records_the_type_the_version_and_the_source(): void
    {
        $user = $this->customer();

        $this->grant('service_terms', ['source' => 'onboarding'])->assertOk();

        $consent = $user->consents()->sole();
        $this->assertSame('service_terms', $consent->type);
        $this->assertSame('v1', $consent->version);
        $this->assertSame('granted', $consent->status);
        $this->assertSame('onboarding', $consent->source);
        $this->assertNotNull($consent->granted_at);
        $this->assertNull($consent->withdrawn_at);

        $this->assertTrue($this->state('service_terms')['is_granted']);
    }

    public function test_the_source_defaults_to_the_screen_it_came_from(): void
    {
        $user = $this->customer();
        $this->grant('ai_memory')->assertOk();

        $this->assertSame('privacy_screen', $user->consents()->sole()->source);
    }

    public function test_granting_twice_records_one_decision(): void
    {
        $user = $this->customer();

        $this->grant('ai_memory')->assertOk();
        $this->grant('ai_memory')->assertOk();
        $this->grant('ai_memory')->assertOk();

        $this->assertSame(1, $user->consents()->count());
        $this->assertTrue($this->state('ai_memory')['is_granted']);
    }

    public function test_it_refuses_a_type_it_does_not_know(): void
    {
        $this->customer();

        $this->grant('sell_my_soul')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_a_client_cannot_choose_the_version_it_is_recorded_under(): void
    {
        $user = $this->customer();

        $this->grant('ai_memory', ['version' => 'v99'])->assertOk();

        // The catalogue decides the version, never the caller.
        $this->assertSame('v1', $user->consents()->sole()->version);
    }

    // --- Withdrawal ---------------------------------------------------------

    public function test_an_optional_consent_can_be_withdrawn(): void
    {
        $user = $this->customer();
        $this->grant('ai_memory')->assertOk();

        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();

        $this->assertFalse($this->state('ai_memory')['is_granted']);
        $this->assertSame('withdrawn', $this->state('ai_memory')['status']);

        // Two rows: the grant is still there, and the withdrawal sits after it.
        $this->assertSame(2, $user->consents()->count());
        $withdrawal = $user->consents()->orderByDesc('id')->first();
        $this->assertSame('v1', $withdrawal->version);
        $this->assertNotNull($withdrawal->withdrawn_at);
    }

    public function test_withdrawing_twice_records_one_withdrawal(): void
    {
        $user = $this->customer();
        $this->grant('ai_memory')->assertOk();

        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();

        $this->assertSame(2, $user->consents()->count());
    }

    public function test_it_can_be_granted_again_after_a_withdrawal(): void
    {
        $user = $this->customer();

        $this->grant('ai_memory')->assertOk();
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();
        $this->grant('ai_memory')->assertOk();

        $this->assertTrue($this->state('ai_memory')['is_granted']);
        $this->assertSame(3, $user->consents()->count());
    }

    public function test_a_required_consent_cannot_be_withdrawn(): void
    {
        $user = $this->customer();
        $this->grant('service_terms')->assertOk();

        $this->postJson('/api/v1/consents/service_terms/withdraw')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This permission is part of using '
                .'FynnEdge and cannot be switched off here. To stop entirely, '
                .'delete your account.');

        $this->assertTrue($this->state('service_terms')['is_granted']);
        $this->assertSame(1, $user->consents()->count());
    }

    public function test_withdrawing_marketing_cannot_switch_the_service_off(): void
    {
        $this->customer();
        $this->grant('service_terms')->assertOk();
        $this->grant('privacy_notice')->assertOk();
        $this->grant('ai_memory')->assertOk();

        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();

        // The optional one is off; both required ones are untouched.
        $this->assertFalse($this->state('ai_memory')['is_granted']);
        $this->assertTrue($this->state('service_terms')['is_granted']);
        $this->assertTrue($this->state('privacy_notice')['is_granted']);
    }

    public function test_withdrawing_something_never_granted_is_refused(): void
    {
        $this->customer();

        $this->postJson('/api/v1/consents/ai_memory/withdraw')
            ->assertStatus(422)
            ->assertJsonPath('message', 'There is nothing to withdraw for that permission.');
    }

    public function test_withdrawing_an_unknown_type_is_a_404(): void
    {
        $this->customer();

        $this->postJson('/api/v1/consents/nonsense/withdraw')->assertNotFound();
    }

    // --- Versioning ---------------------------------------------------------

    public function test_a_consent_under_old_wording_does_not_carry_forward(): void
    {
        $user = $this->customer();

        // A decision made under wording that has since been replaced.
        $user->consents()->create([
            'type' => 'privacy_notice',
            'version' => 'v0',
            'status' => 'granted',
            'granted_at' => now(),
            'source' => 'onboarding',
        ]);

        $state = $this->state('privacy_notice');

        $this->assertFalse($state['is_granted'], 'v0 is not consent to v1');
        $this->assertTrue($state['needs_reconfirmation']);
        $this->assertSame('v0', $state['granted_version']);
        $this->assertSame('v1', $state['current_version']);
    }

    public function test_confirming_a_new_version_keeps_the_old_record(): void
    {
        $user = $this->customer();

        $user->consents()->create([
            'type' => 'privacy_notice',
            'version' => 'v0',
            'status' => 'granted',
            'granted_at' => now(),
            'source' => 'onboarding',
        ]);

        $this->grant('privacy_notice')->assertOk();

        $versions = $user->consents()->orderBy('id')->pluck('version')->all();

        $this->assertSame(['v0', 'v1'], $versions);
        $this->assertTrue($this->state('privacy_notice')['is_granted']);
        $this->assertFalse($this->state('privacy_notice')['needs_reconfirmation']);
    }

    // --- Immutability -------------------------------------------------------

    public function test_a_consent_record_cannot_be_edited(): void
    {
        $user = $this->customer();
        $this->grant('ai_memory')->assertOk();
        $consent = $user->consents()->sole();

        $this->expectException(RuntimeException::class);
        $consent->update(['status' => 'withdrawn']);
    }

    public function test_a_consent_record_cannot_be_deleted(): void
    {
        $user = $this->customer();
        $this->grant('ai_memory')->assertOk();
        $consent = $user->consents()->sole();

        $this->expectException(RuntimeException::class);
        $consent->delete();
    }

    public function test_there_is_no_endpoint_that_edits_or_removes_history(): void
    {
        $this->customer();
        $this->grant('ai_memory')->assertOk();

        // Either "wrong method" or "no such route": both mean the operation
        // does not exist. What matters is that none of them succeed.
        foreach ([
            ['put', '/api/v1/consents'],
            ['patch', '/api/v1/consents'],
            ['delete', '/api/v1/consents'],
            ['delete', '/api/v1/consents/ai_memory'],
            ['delete', '/api/v1/consents/history'],
            ['put', '/api/v1/consents/ai_memory'],
        ] as [$method, $path]) {
            $response = $this->json($method, $path, ['type' => 'ai_memory']);

            $this->assertContains(
                $response->status(),
                [404, 405],
                "{$method} {$path} should not be routable"
            );
        }

        // And the record is exactly as it was.
        $this->assertSame(1, Consent::count());
        $this->assertTrue(Consent::sole()->isGranted());
    }

    // --- History ------------------------------------------------------------

    public function test_history_reads_newest_first_and_keeps_everything(): void
    {
        $this->customer();

        $this->grant('service_terms')->assertOk();
        $this->grant('ai_memory')->assertOk();
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertOk();

        $history = $this->getJson('/api/v1/consents/history')->assertOk()->json('data');

        $this->assertCount(3, $history);
        $this->assertSame(
            [
                ['ai_memory', 'withdrawn'],
                ['ai_memory', 'granted'],
                ['service_terms', 'granted'],
            ],
            array_map(fn ($e) => [$e['type'], $e['status']], $history),
        );

        foreach ($history as $entry) {
            $this->assertNotNull($entry['at']);
            $this->assertSame('v1', $entry['version']);
            $this->assertSame('privacy_screen', $entry['source']);
        }
    }

    public function test_history_exposes_no_database_identifiers(): void
    {
        $this->customer();
        $this->grant('ai_memory')->assertOk();

        $entry = $this->getJson('/api/v1/consents/history')->json('data.0');

        $this->assertSame(
            ['type', 'label', 'version', 'status', 'status_label', 'source', 'at'],
            array_keys($entry),
        );
        $this->assertArrayNotHasKey('id', $entry);
        $this->assertArrayNotHasKey('user_id', $entry);
    }

    // --- Ownership ----------------------------------------------------------

    public function test_the_whole_thing_requires_a_session(): void
    {
        $this->getJson('/api/v1/consents')->assertStatus(401);
        $this->postJson('/api/v1/consents', ['type' => 'ai_memory'])->assertStatus(401);
        $this->postJson('/api/v1/consents/ai_memory/withdraw')->assertStatus(401);
        $this->getJson('/api/v1/consents/history')->assertStatus(401);
    }

    public function test_a_customer_sees_only_their_own_consent(): void
    {
        $other = $this->customer();
        $this->grant('ai_memory')->assertOk();

        $this->customer();

        $this->assertFalse($this->state('ai_memory')['is_granted']);
        $this->assertSame([], $this->getJson('/api/v1/consents/history')->json('data'));
        $this->assertSame(1, $other->consents()->count());
    }

    public function test_a_user_id_in_the_payload_never_establishes_ownership(): void
    {
        $victim = $this->customer();
        $me = $this->customer();

        $this->grant('ai_memory', [
            'user_id' => $victim->id,
            'customer_id' => $victim->id,
        ])->assertOk();

        $this->assertSame(0, $victim->consents()->count());
        $this->assertSame(1, $me->consents()->count());
    }

    // --- The consent actually does something --------------------------------

    public function test_ai_memory_consent_governs_memory_not_the_conversation(): void
    {
        $user = $this->customer();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
        ]);

        $conversation = $this->postJson('/api/v1/ai/conversations')
            ->assertCreated()->json('data.id');

        $ask = fn () => $this->postJson(
            "/api/v1/ai/conversations/{$conversation}/messages",
            ['text' => 'How am I doing financially?'],
        )->assertOk()->json('data.text');

        // Without consent FynnAI still answers this conversation from the
        // customer's own figures. What consent governs is whether anything
        // is kept for the next one — the two are different things, and
        // conflating them would either cripple the assistant or retain
        // personal context nobody agreed to.
        $withoutConsent = $ask();
        $this->assertStringContainsString('₹1,00,000', $withoutConsent);
        $this->assertSame(0, $user->aiMemories()->count());
        $this->assertSame(
            [],
            $this->getJson('/api/v1/ai/context')->json('data.context.memories'),
        );
        $this->assertFalse(
            $this->getJson('/api/v1/ai/memory')->json('data.memory_enabled'),
        );

        // Granting it turns persistent memory on, and the conversation is
        // unchanged by that.
        $this->grant('ai_memory')->assertOk();

        $this->assertTrue(
            $this->getJson('/api/v1/ai/memory')->json('data.memory_enabled'),
        );
        $this->assertStringContainsString('₹1,00,000', $ask());
    }

    public function test_fynn_ai_never_reads_another_customers_figures(): void
    {
        $rich = User::factory()->create();
        $rich->ensureProfiles();
        $rich->financialProfile->update(['monthly_income' => 5000000]);

        $user = $this->customer();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
        ]);

        $conversation = $this->postJson('/api/v1/ai/conversations')
            ->json('data.id');

        $reply = $this->postJson(
            "/api/v1/ai/conversations/{$conversation}/messages",
            [
                'text' => 'How am I doing financially?',
                'user_id' => $rich->id,
                'customer_id' => $rich->id,
            ],
        )->assertOk()->json('data.text');

        $this->assertStringContainsString('₹1,00,000', $reply);
        $this->assertStringNotContainsString('50,00,000', $reply);
    }

    public function test_one_customer_cannot_withdraw_anothers_consent(): void
    {
        $victim = $this->customer();
        $this->grant('ai_memory')->assertOk();

        $this->customer();

        // Withdrawal is scoped to the caller, so this is "you have not
        // granted that", never a change to somebody else's record.
        $this->postJson('/api/v1/consents/ai_memory/withdraw', [
            'user_id' => $victim->id,
        ])->assertStatus(422);

        $this->assertTrue($victim->consents()->sole()->isGranted());
    }
}
