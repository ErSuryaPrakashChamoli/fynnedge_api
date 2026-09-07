<?php

namespace Tests\Feature\Credit;

use App\Domain\Consent\ConsentType;
use App\Domain\Credit\CreditBureauAdapter;
use App\Domain\Credit\CreditBureauUnavailable;
use App\Domain\Credit\CreditReportStatus;
use App\Domain\Credit\Fixture\FixtureCreditBureauAdapter;
use App\Domain\Credit\Fixture\FixtureCreditNormalizer;
use App\Models\CreditReport;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\Credit\CreditBureauService;
use App\Services\Credit\CreditConsentRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Credit information: what FynnEdge may ask, what it keeps, and what it
 * refuses to say.
 *
 * NO LIVE BUREAU IS CONNECTED. Everything here runs against a fictional one.
 * No real person's credit information is involved anywhere in this suite.
 */
class CreditBureauTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A bureau exists for these tests. The default is 'none'.
        config(['fynnedge.credit.driver' => 'fixture']);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        return $user;
    }

    private function service(?CreditBureauAdapter $adapter = null): CreditBureauService
    {
        return new CreditBureauService(
            app(ConsentService::class),
            $adapter ?? new FixtureCreditBureauAdapter,
            new FixtureCreditNormalizer,
        );
    }

    private function consent(User $user): void
    {
        app(ConsentService::class)->grant(
            $user,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );
    }

    // --- Consent is the gate ------------------------------------------------

    public function test_without_consent_no_bureau_is_asked(): void
    {
        $user = $this->customer();

        $adapter = new class extends FixtureCreditBureauAdapter
        {
            public int $calls = 0;

            public function fetch(User $user): array
            {
                $this->calls++;

                return parent::fetch($user);
            }
        };

        try {
            $this->service($adapter)->check($user);
            $this->fail('a bureau was asked without consent');
        } catch (CreditConsentRequired) {
            // The point: not merely that it failed, but that nothing was
            // asked. A bureau enquiry nobody agreed to cannot be undone.
            $this->assertSame(0, $adapter->calls);
            $this->assertSame(0, CreditReport::count());
        }
    }

    public function test_a_withdrawn_consent_stops_future_checks(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $this->service()->check($user);
        $this->assertSame(1, CreditReport::count());

        app(ConsentService::class)->withdraw(
            $user,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );

        $this->expectException(CreditConsentRequired::class);
        $this->service()->check($user->fresh(), force: true);
    }

    public function test_withdrawing_consent_deletes_what_was_obtained(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->service()->check($user);

        $this->assertSame(1, CreditReport::count());

        app(ConsentService::class)->withdraw(
            $user,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );

        // FynnEdge asked on a permission that no longer stands, and has no
        // other purpose it could honestly claim for keeping the answer.
        $this->assertSame(0, CreditReport::count());

        // The ledger keeps the decision. That has to survive.
        $history = app(ConsentService::class)->history($user)
            ->where('type', ConsentType::CreditBureauCheck->value);
        $this->assertCount(2, $history);
    }

    public function test_a_customer_cannot_choose_the_consent_version(): void
    {
        $user = $this->customer();

        $this->postJson('/api/v1/consents', [
            'type' => 'credit_bureau_check',
            'version' => 'v999',
        ])->assertOk();

        $consent = $user->consents()
            ->where('type', 'credit_bureau_check')
            ->latest('id')
            ->first();

        $this->assertNotSame('v999', $consent->version);
        $this->assertSame(
            ConsentType::CreditBureauCheck->currentVersion(),
            $consent->version,
        );
    }

    public function test_the_consent_is_not_offered_when_no_bureau_exists(): void
    {
        // Module 10's rule, unchanged: permission is only collected for a
        // capability that exists. Module 23 built one, so the consent
        // follows the capability rather than being switched on beside it.
        config(['fynnedge.credit.driver' => 'none']);

        $this->assertFalse(ConsentType::CreditBureauCheck->isAvailable());
        $this->assertNull(ConsentType::CreditBureauCheck->currentVersion());

        $this->customer();
        $this->postJson('/api/v1/consents', ['type' => 'credit_bureau_check'])
            ->assertStatus(422);
    }

    // --- What is kept --------------------------------------------------------

    public function test_almost_nothing_from_the_report_is_kept(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->service()->check($user);

        $stored = CreditReport::all()->toJson();

        // The fixture response carries all of this. None of it survives the
        // normalizer.
        foreach ([
            'Sample Person', 'AAAAA0000A', '9000000000', '1990-01-01',
            'Sample Street', 'Sample Employer', '00000000001234',
            'accounts', 'enquiries', 'addresses', 'employment',
            'consumerName', 'dateOfBirth',
        ] as $discarded) {
            $this->assertStringNotContainsString($discarded, $stored, $discarded);
        }

        // What is kept: a score, its model, its scale, and when.
        $report = CreditReport::first();
        $this->assertSame(742, (int) $report->score);
        $this->assertSame('SAMPLE-SCORE-V1', $report->score_model);
        $this->assertSame(300, (int) $report->score_min);
        $this->assertSame(900, (int) $report->score_max);
        $this->assertNotNull($report->obtained_at);
    }

    public function test_the_bureau_reference_never_reaches_the_customer(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->service()->check($user);

        $this->assertNotNull(CreditReport::first()->bureau_reference);

        $body = $this->getJson('/api/v1/credit/status')->assertOk()->getContent();
        $this->assertStringNotContainsString('bureau_reference', $body);
        $this->assertStringNotContainsString('SMP-', $body);
    }

    public function test_a_score_is_never_separated_from_its_scale(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->service()->check($user);

        $profile = $this->getJson('/api/v1/credit/status')->json('data.profile');

        // 742 means nothing without knowing it is out of 900 and whose it is.
        $this->assertSame(742, $profile['score']);
        $this->assertSame(['min' => 300, 'max' => 900], $profile['scale']);
        $this->assertSame('SAMPLE-SCORE-V1', $profile['score_model']);
        $this->assertTrue($profile['is_sample']);
    }

    // --- A bureau score is not a FynnScore -------------------------------------

    public function test_the_bureau_score_never_touches_the_fynn_score(): void
    {
        $user = $this->customer();
        $user->financialProfile->update([
            'monthly_income' => 100000,
            'monthly_expenses' => 40000,
            'existing_emi' => 20000,
            'savings' => 600000,
        ]);

        $before = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');

        $this->consent($user);
        $this->service(new FixtureCreditBureauAdapter(FixtureCreditBureauAdapter::LOW))
            ->check($user);

        $after = $this->getJson('/api/v1/fynn-score')->assertOk()->json('data');

        // A bureau score of 598 changes nothing about FynnEdge's own view of
        // this customer's financial health. They are different numbers,
        // measuring different things, on different scales.
        $this->assertSame($before, $after);
        $this->assertCount(3, $after['dimensions']);
    }

    public function test_no_credit_data_appears_in_the_score_or_on_home(): void
    {
        $user = $this->customer();
        $user->financialProfile->update(['monthly_income' => 100000]);
        $this->consent($user);
        $this->service()->check($user);

        foreach (['/api/v1/fynn-score', '/api/v1/home', '/api/v1/ai/context'] as $uri) {
            $body = $this->getJson($uri)->assertOk()->getContent();

            // Named fields rather than a bare number, which can collide with
            // an unrelated timestamp or ratio and prove nothing either way.
            foreach ([
                'credit_score', 'score_model', 'bureau_label', 'bureau_reference',
                'SAMPLE-SCORE-V1', 'credit_report', 'creditScore',
            ] as $leak) {
                $this->assertStringNotContainsString($leak, $body, "{$uri}: {$leak}");
            }
        }

        // And the score's own disclaimer still says what FynnScore is not.
        $this->assertStringContainsString(
            'not a credit bureau score',
            $this->getJson('/api/v1/fynn-score')->json('data.disclaimer'),
        );
    }

    // --- Failure is never a score ----------------------------------------------

    public function test_an_unreachable_bureau_invents_nothing(): void
    {
        $user = $this->customer();
        $this->consent($user);

        try {
            $this->service(new FixtureCreditBureauAdapter(unavailable: true))
                ->check($user);
            $this->fail('a failure produced a result');
        } catch (CreditBureauUnavailable) {
            $this->assertSame(0, CreditReport::count());
        }

        $status = $this->getJson('/api/v1/credit/status')->json('data');
        $this->assertSame('not_requested', $status['status']);
        // Not a zero standing in for "we do not know".
        $this->assertNull($status['profile']);
    }

    public function test_a_failure_never_reuses_an_older_report_as_current(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $first = $this->service()->check($user);
        $this->assertSame(742, $first->score);

        // The bureau goes down. A forced check must fail rather than hand
        // back the old report as though it were fresh.
        $this->expectException(CreditBureauUnavailable::class);
        $this->service(new FixtureCreditBureauAdapter(unavailable: true))
            ->check($user, force: true);
    }

    public function test_an_unreadable_response_is_refused(): void
    {
        $user = $this->customer();
        $this->consent($user);

        // A score with no stated scale. Assuming 300–900 would compare it
        // against a documented minimum that may be measured differently.
        $adapter = new FixtureCreditBureauAdapter(response: [
            'creditScore' => 742,
            'scoreType' => 'MYSTERY-V2',
            'reportDate' => '2026-09-06T10:00:00+05:30',
        ]);

        $this->expectException(CreditBureauUnavailable::class);
        $this->service($adapter)->check($user);
    }

    public function test_unknown_provider_fields_are_ignored_safely(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $response = FixtureCreditBureauAdapter::report(742);
        $response['someNewFieldTheBureauAdded'] = ['nested' => 'value'];

        $profile = $this->service(new FixtureCreditBureauAdapter(response: $response))
            ->check($user);

        $this->assertSame(742, $profile->score);
        $this->assertStringNotContainsString(
            'someNewFieldTheBureauAdded',
            CreditReport::all()->toJson(),
        );
    }

    public function test_with_no_bureau_configured_nothing_can_be_checked(): void
    {
        config(['fynnedge.credit.driver' => 'none']);
        $this->customer();

        $service = new CreditBureauService(app(ConsentService::class));
        $this->assertFalse($service->isConfigured());

        $this->postJson('/api/v1/credit/check')
            ->assertStatus(501)
            ->assertJsonPath('reason', 'not_configured');
    }

    // --- Cost control -----------------------------------------------------------

    public function test_a_repeated_tap_is_not_a_repeated_pull(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $adapter = new class extends FixtureCreditBureauAdapter
        {
            public int $calls = 0;

            public function fetch(User $user): array
            {
                $this->calls++;

                return parent::fetch($user);
            }
        };

        $service = $this->service($adapter);
        $service->check($user);
        $service->check($user);
        $service->check($user);

        // A pull may cost money and be recorded as an enquiry against the
        // customer's name. Three taps is one intention.
        $this->assertSame(1, $adapter->calls);
        $this->assertSame(1, CreditReport::count());
    }

    public function test_a_report_past_the_reuse_window_is_requested_again(): void
    {
        $user = $this->customer();
        $this->consent($user);
        $this->service()->check($user);

        CreditReport::first()->update([
            'obtained_at' => Carbon::now()->subDays(400),
        ]);

        $this->service()->check($user);

        $this->assertSame(2, CreditReport::count());
    }

    public function test_checks_are_rate_limited_far_below_the_global_allowance(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->postJson('/api/v1/credit/check')->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'credit checks are unbounded');
    }

    // --- Ownership ----------------------------------------------------------------

    public function test_one_customer_never_sees_anothers_credit_information(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        Sanctum::actingAs($other);
        $this->consent($other);
        $this->service()->check($other);

        $this->assertSame(742, (int) CreditReport::first()->score);

        // A different customer, who has never had a check.
        $this->customer();

        $status = $this->getJson('/api/v1/credit/status')->assertOk()->json('data');
        $this->assertSame('consent_required', $status['status']);
        $this->assertNull($status['profile']);

        $body = $this->getJson('/api/v1/credit/status')->getContent();
        $this->assertStringNotContainsString('742', $body);
    }

    public function test_an_identity_in_the_payload_never_wins(): void
    {
        $other = User::factory()->create();
        $other->ensureProfiles();
        Sanctum::actingAs($other);
        $this->consent($other);
        $this->service()->check($other);

        $mine = $this->customer();
        $this->consent($mine);

        $this->postJson('/api/v1/credit/check', [
            'user_id' => $other->id,
            'customer_id' => $other->id,
        ])->assertOk();

        // Two reports, one each. Nothing was read from or written to the
        // other customer's.
        $this->assertSame(1, $other->creditReports()->count());
        $this->assertSame(1, $mine->creditReports()->count());
    }

    public function test_the_whole_surface_requires_a_session(): void
    {
        $this->getJson('/api/v1/credit/status')->assertStatus(401);
        $this->postJson('/api/v1/credit/check')->assertStatus(401);
    }

    // --- Status ---------------------------------------------------------------------

    public function test_the_status_walks_the_customer_through_it(): void
    {
        config(['fynnedge.credit.driver' => 'none']);
        $user = $this->customer();

        $this->assertSame(
            CreditReportStatus::NotConfigured,
            (new CreditBureauService(app(ConsentService::class)))->status($user),
        );

        config(['fynnedge.credit.driver' => 'fixture']);
        $this->assertSame(CreditReportStatus::ConsentRequired, $this->service()->status($user));

        $this->consent($user);
        $this->assertSame(CreditReportStatus::NotRequested, $this->service()->status($user));

        $this->service()->check($user);
        $this->assertSame(CreditReportStatus::Available, $this->service()->status($user));
    }

    public function test_reading_the_status_never_asks_a_bureau(): void
    {
        $user = $this->customer();
        $this->consent($user);

        $adapter = new class extends FixtureCreditBureauAdapter
        {
            public int $calls = 0;

            public function fetch(User $user): array
            {
                $this->calls++;

                return parent::fetch($user);
            }
        };

        $service = $this->service($adapter);

        // Safe to call on any screen. A bureau pull happens only when the
        // customer asks for one.
        for ($i = 0; $i < 5; $i++) {
            $service->status($user);
            $service->profile($user);
        }

        $this->assertSame(0, $adapter->calls);
    }
}
