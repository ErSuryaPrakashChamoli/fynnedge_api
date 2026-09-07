<?php

namespace Tests\Feature\Api;

use App\Domain\Financial\FinancialEngine;
use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeCockpitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
    }

    private function customer(array $financials = []): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Rahul Sharma']);
        $user->financialProfile->update(array_merge([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'other_obligations' => 0,
            'savings' => 600000,
        ], $financials));
        Sanctum::actingAs($user);

        return $user;
    }

    private function home(): array
    {
        return $this->getJson('/api/v1/home')->assertOk()->json('data');
    }

    /** @return list<string> */
    private function attentionKeys(): array
    {
        return array_column($this->home()['attention'], 'key');
    }

    // --- The shape ----------------------------------------------------------

    public function test_it_returns_the_cockpit_for_the_authenticated_customer(): void
    {
        $this->customer();

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'customer' => ['first_name', 'has_financial_profile'],
                    'financial' => [
                        'monthly_income', 'monthly_outgo', 'surplus',
                        'existing_emi', 'savings', 'emi_ratio',
                        'emergency_months', 'has_income',
                    ],
                    'score',
                    'goals' => ['items', 'total'],
                    'applications' => ['items', 'total', 'active'],
                    'vault' => ['document_count'],
                    'attention',
                    'primary_action' => ['key', 'label', 'route'],
                ],
            ]);
    }

    public function test_it_greets_by_first_name_only(): void
    {
        $this->customer();

        $this->assertSame('Rahul', $this->home()['customer']['first_name']);
    }

    public function test_a_customer_with_no_name_is_not_given_one(): void
    {
        $user = $this->customer();
        $user->customerProfile->update(['full_name' => '']);

        $home = $this->home();

        $this->assertNull($home['customer']['first_name']);
        // And is offered the way to fix it rather than being greeted by a
        // name FynnEdge chose.
        $this->assertSame('add_your_name', $home['primary_action']['key']);
        $this->assertSame('/profile', $home['primary_action']['route']);
    }

    // --- Everything traces to an engine -------------------------------------

    public function test_the_figures_are_the_financial_engines(): void
    {
        $this->customer();

        $home = $this->home()['financial'];
        $profile = $this->getJson('/api/v1/financial-profile')->json('data');

        $this->assertSame($profile['derived']['surplus'], $home['surplus']);
        $this->assertSame($profile['derived']['monthly_outgo'], $home['monthly_outgo']);
        $this->assertSame($profile['derived']['emi_ratio'], $home['emi_ratio']);
        $this->assertSame(
            $profile['derived']['emergency_months'],
            $home['emergency_months'],
        );
    }

    public function test_the_score_is_the_one_fynn_score_serves(): void
    {
        $this->customer();

        $home = $this->home()['score'];
        $score = $this->getJson('/api/v1/fynn-score')->json('data');

        $this->assertSame($score['score'], $home['score']);
        $this->assertSame($score['band'], $home['band']);
        $this->assertSame($score['model_version'], $home['model_version']);
        // The three official dimensions, not a Home invention.
        $this->assertCount(3, $home['dimensions']);
    }

    public function test_home_introduces_no_score_of_its_own(): void
    {
        $this->customer();
        $body = $this->getJson('/api/v1/home')->getContent();

        foreach ([
            'health_score', 'risk_score', 'impact_score', 'attention_score',
            'wellness', 'rating',
        ] as $invented) {
            $this->assertStringNotContainsString($invented, $body);
        }
    }

    // --- Attention ----------------------------------------------------------

    public function test_a_healthy_customer_is_told_nothing(): void
    {
        // Inside every reference: no manufactured concern.
        $this->customer([
            'monthly_income' => 200000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 1000000,
        ]);

        $this->assertSame([], $this->home()['attention']);
    }

    public function test_a_negative_surplus_is_the_first_thing_said(): void
    {
        $this->customer([
            'monthly_income' => 50000,
            'monthly_expenses' => 45000,
            'existing_emi' => 30000,
            'savings' => 10000,
        ]);

        $keys = $this->attentionKeys();

        // Every rule fires for this customer; the order is fixed.
        $this->assertSame(
            ['negative_surplus', 'emi_ratio_past_reference', 'thin_emergency_buffer'],
            $keys,
        );
        $this->assertSame('serious', $this->home()['attention'][0]['severity']);
    }

    public function test_the_emi_rule_uses_the_existing_reference(): void
    {
        // 46% of income in EMIs: past the 45% FynnEdge already uses.
        $this->customer([
            'monthly_income' => 100000,
            'monthly_expenses' => 20000,
            'existing_emi' => 46000,
            'savings' => 1000000,
        ]);

        $item = collect($this->home()['attention'])
            ->firstWhere('key', 'emi_ratio_past_reference');

        $this->assertNotNull($item);
        $this->assertStringContainsString('46%', $item['detail']);
        $this->assertStringContainsString(
            (string) (int) FinancialEngine::AFFORDABILITY_CEILING_PERCENT,
            $item['detail'],
        );
        // FynnEdge's reference, never a lender's decision.
        $this->assertStringContainsString('not a lender rule', $item['detail']);
    }

    public function test_exactly_on_the_reference_is_not_flagged(): void
    {
        $this->customer([
            'monthly_income' => 100000,
            'monthly_expenses' => 20000,
            'existing_emi' => 45000,
            'savings' => 1000000,
        ]);

        $this->assertNotContains('emi_ratio_past_reference', $this->attentionKeys());
    }

    public function test_the_buffer_rule_uses_the_six_month_target(): void
    {
        // Six months of outgo saved exactly: not flagged.
        $this->customer([
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 360000,
        ]);
        $this->assertNotContains('thin_emergency_buffer', $this->attentionKeys());

        // A rupee less, and it is.
        $this->customer(['savings' => 300000]);
        $this->assertContains('thin_emergency_buffer', $this->attentionKeys());
    }

    public function test_an_overdue_goal_is_surfaced_from_its_own_metrics(): void
    {
        $user = $this->customer(['savings' => 600000]);
        $user->goals()->create([
            'title' => 'New premises',
            'category' => 'business',
            'target_amount' => 500000,
            'saved_amount' => 100000,
            'target_date' => now()->subMonths(2),
        ]);

        $item = collect($this->home()['attention'])
            ->firstWhere('key', 'goal_overdue');

        $this->assertNotNull($item);
        $this->assertStringContainsString('New premises', $item['title']);
    }

    public function test_no_attention_is_claimed_without_an_income(): void
    {
        $this->customer([
            'monthly_income' => 0,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
            'savings' => 0,
        ]);

        // Nothing can be measured, so nothing is asserted about it.
        $this->assertSame([], $this->home()['attention']);
    }

    public function test_no_application_action_is_invented(): void
    {
        $this->customer();
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/applications/'.$application['id'].'/submit', [
            'acknowledged' => true,
        ])->assertOk();

        // Submitted is not "the lender wants something": no provider has
        // seen it, so no action item exists.
        $this->assertNotContains('application_action', $this->attentionKeys());
    }

    // --- Goals and applications ---------------------------------------------

    public function test_goals_are_the_customers_own_nearest_first(): void
    {
        $user = $this->customer();

        foreach ([['Later', 300], ['Soonest', 30], ['Middle', 120]] as [$title, $days]) {
            $user->goals()->create([
                'title' => $title,
                'category' => 'other',
                'target_amount' => 100000,
                'target_date' => now()->addDays($days),
            ]);
        }

        $home = $this->home()['goals'];

        $this->assertSame(
            ['Soonest', 'Middle', 'Later'],
            array_column($home['items'], 'title'),
        );
        $this->assertSame(3, $home['total']);
    }

    public function test_only_a_few_goals_are_shown_and_the_total_says_so(): void
    {
        $user = $this->customer();

        for ($i = 1; $i <= 5; $i++) {
            $user->goals()->create([
                'title' => 'Goal '.$i,
                'category' => 'other',
                'target_amount' => 100000,
                'target_date' => now()->addDays(30 * $i),
            ]);
        }

        $home = $this->home()['goals'];

        // Three on screen, and the count that tells the customer there are
        // more rather than hiding them.
        $this->assertCount(3, $home['items']);
        $this->assertSame(5, $home['total']);
    }

    public function test_a_simulated_application_stays_simulated_on_home(): void
    {
        $this->customer();
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data');

        $this->postJson('/api/v1/applications/'.$application['id'].'/submit', [
            'acknowledged' => true,
        ])->assertOk();

        $home = $this->home()['applications'];

        $this->assertSame(1, $home['total']);
        $this->assertSame(1, $home['active']);
        $this->assertSame('submitted', $home['items'][0]['status']);
        $this->assertTrue($home['items'][0]['is_simulated']);
        $this->assertSame('United Credit Bank', $home['items'][0]['lender']);
    }

    public function test_a_cancelled_application_is_not_counted_as_active(): void
    {
        $this->customer();
        $id = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->json('data.id');

        $this->postJson('/api/v1/applications/'.$id.'/cancel')->assertOk();

        $home = $this->home()['applications'];
        $this->assertSame(1, $home['total']);
        $this->assertSame(0, $home['active']);
    }

    public function test_the_vault_count_is_real_and_carries_nothing_else(): void
    {
        $this->customer();
        $this->assertSame(0, $this->home()['vault']['document_count']);

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'slip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated();

        $home = $this->home();
        $this->assertSame(1, $home['vault']['document_count']);
        // A count, and nothing about what is in the file.
        $this->assertSame(['document_count'], array_keys($home['vault']));
    }

    // --- The next step ------------------------------------------------------

    public function test_the_primary_action_follows_what_the_customer_has(): void
    {
        // No profile: finish it.
        $this->customer([
            'monthly_income' => 0,
            'monthly_expenses' => 0,
            'existing_emi' => 0,
        ]);
        $this->assertSame('complete_profile', $this->home()['primary_action']['key']);

        // Profile but no goals: set one.
        $user = $this->customer();
        $this->assertSame('set_goal', $this->home()['primary_action']['key']);

        // A goal but nothing applied for: explore.
        $user->goals()->create([
            'title' => 'A goal',
            'category' => 'other',
            'target_amount' => 100000,
            'target_date' => now()->addYear(),
        ]);
        $this->assertSame('explore_options', $this->home()['primary_action']['key']);

        // An application: follow it.
        $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated();
        $this->assertSame('view_applications', $this->home()['primary_action']['key']);
    }

    // --- Security -----------------------------------------------------------

    public function test_home_requires_a_session(): void
    {
        $this->getJson('/api/v1/home')->assertStatus(401);
    }

    public function test_home_never_shows_another_customers_anything(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        $other->customerProfile->update(['full_name' => 'Someone Else']);
        $other->financialProfile->update(['monthly_income' => 5000000]);
        $other->goals()->create([
            'title' => 'Their secret goal',
            'category' => 'other',
            'target_amount' => 999999,
            'target_date' => now()->addYear(),
        ]);

        $this->customer();
        $body = $this->getJson('/api/v1/home')->assertOk()->getContent();

        $this->assertStringNotContainsString('Their secret goal', $body);
        $this->assertStringNotContainsString('Someone Else', $body);
        $this->assertStringNotContainsString('5000000', $body);
        $this->assertSame(100000, $this->home()['financial']['monthly_income']);
    }

    public function test_a_user_id_in_the_query_is_ignored(): void
    {
        $rich = User::factory()->create();
        $rich->ensureProfiles();
        $rich->financialProfile->update(['monthly_income' => 5000000]);

        $this->customer();

        $this->getJson('/api/v1/home?user_id='.$rich->id.'&customer_id='.$rich->id)
            ->assertOk()
            ->assertJsonPath('data.financial.monthly_income', 100000);
    }

    public function test_home_leaks_no_storage_or_internal_detail(): void
    {
        $this->customer();
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'slip.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated();

        $body = $this->getJson('/api/v1/home')->getContent();

        foreach ([
            'storage_key', 'storage_disk', storage_path(), 'password',
            'remember_token', 'api_key', 'secret',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    // --- Efficiency ---------------------------------------------------------

    public function test_home_does_not_query_per_goal_or_per_application(): void
    {
        $user = $this->customer();

        for ($i = 1; $i <= 6; $i++) {
            $user->goals()->create([
                'title' => 'Goal '.$i,
                'category' => 'other',
                'target_amount' => 100000,
                'target_date' => now()->addDays(30 * $i),
            ]);
        }
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/applications', [
                'product_id' => 'prod_bl_b',
                'amount' => 1000000,
                'tenure_months' => 60,
            ])->assertCreated();
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->getJson('/api/v1/home')->assertOk();

        // A handful of collection queries, not one per goal or application.
        // Ten records would make an N+1 obvious here.
        $this->assertLessThanOrEqual(
            10,
            $queries,
            "Home ran {$queries} queries — that looks like an N+1."
        );
    }
}
