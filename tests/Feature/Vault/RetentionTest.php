<?php

namespace Tests\Feature\Vault;

use App\Domain\Consent\ConsentType;
use App\Models\CreditReport;
use App\Models\ProviderEvent;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What FynnEdge keeps, and what it will not decide for you.
 *
 * The mechanism exists so a retention decision is enforceable the day it is
 * taken. The default is deliberately to keep everything: a period chosen by
 * an engineer to make a table smaller would delete a customer's record on a
 * date nobody agreed to.
 */
class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();

        return $user;
    }

    private function creditReport(User $user, int $daysAgo): CreditReport
    {
        return CreditReport::create([
            'user_id' => $user->getKey(),
            'bureau' => 'fixture',
            'score' => 742,
            'score_model' => 'SAMPLE-SCORE-V1',
            'score_min' => 300,
            'score_max' => 900,
            'obtained_at' => Carbon::now()->subDays($daysAgo),
        ]);
    }

    private function providerEvent(int $daysAgo): ProviderEvent
    {
        return ProviderEvent::create([
            'provider_code' => 'fixture',
            'idempotency_key' => 'fixture:'.uniqid(),
            'event_type' => 'submission_received',
            'provider_reference' => 'GW-1',
            'is_simulated' => true,
            'processing_status' => 'processed',
            'occurred_at' => Carbon::now()->subDays($daysAgo),
            'received_at' => Carbon::now()->subDays($daysAgo),
        ]);
    }

    // --- The default -------------------------------------------------------

    public function test_no_period_is_configured_by_default(): void
    {
        foreach ([
            'credit_reports_days', 'document_scans_days',
            'kyc_verifications_days', 'provider_events_days',
        ] as $setting) {
            $this->assertNull(
                config('fynnedge.retention.'.$setting),
                "{$setting} has a period FynnEdge chose for itself",
            );
        }
    }

    public function test_with_no_policy_nothing_is_deleted_however_old(): void
    {
        $user = $this->customer();
        $this->creditReport($user, 3650);
        $this->providerEvent(3650);

        $this->artisan('fynn:retention --apply')
            ->expectsOutputToContain('No retention period is configured')
            ->assertSuccessful();

        // Ten years old, and kept — because nobody has said otherwise.
        $this->assertSame(1, CreditReport::count());
        $this->assertSame(1, ProviderEvent::count());
    }

    public function test_an_empty_setting_never_becomes_zero(): void
    {
        // A blank variable meaning "delete everything immediately" is the
        // worst possible misreading of an unset policy.
        config(['fynnedge.retention.credit_reports_days' => null]);

        $user = $this->customer();
        $this->creditReport($user, 1);

        $this->artisan('fynn:retention --apply')->assertSuccessful();

        $this->assertSame(1, CreditReport::count());
    }

    // --- A configured policy -----------------------------------------------

    public function test_a_configured_period_removes_only_what_it_covers(): void
    {
        config(['fynnedge.retention.credit_reports_days' => 30]);

        $user = $this->customer();
        $old = $this->creditReport($user, 60);
        $recent = $this->creditReport($user, 5);

        $this->artisan('fynn:retention --apply')->assertSuccessful();

        $this->assertNull(CreditReport::find($old->getKey()));
        $this->assertNotNull(CreditReport::find($recent->getKey()));
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        config(['fynnedge.retention.credit_reports_days' => 30]);

        $user = $this->customer();
        $this->creditReport($user, 60);

        // The default is to report, not to act.
        $this->artisan('fynn:retention')
            ->expectsOutputToContain('Nothing was deleted')
            ->assertSuccessful();

        $this->assertSame(1, CreditReport::count());
    }

    public function test_age_is_measured_from_when_the_fact_happened(): void
    {
        config(['fynnedge.retention.credit_reports_days' => 30]);

        $user = $this->customer();

        // Written today, obtained from the bureau two months ago. A report
        // is old from when the bureau answered, not from when the row was
        // inserted.
        $report = $this->creditReport($user, 60);
        $report->forceFill(['created_at' => Carbon::now()])->save();

        $this->artisan('fynn:retention --apply')->assertSuccessful();

        $this->assertSame(0, CreditReport::count());
    }

    public function test_each_kind_is_governed_separately(): void
    {
        config([
            'fynnedge.retention.credit_reports_days' => 30,
            'fynnedge.retention.provider_events_days' => null,
        ]);

        $user = $this->customer();
        $this->creditReport($user, 60);
        $this->providerEvent(60);

        $this->artisan('fynn:retention --apply')->assertSuccessful();

        $this->assertSame(0, CreditReport::count());

        // No policy for events, so events are kept.
        $this->assertSame(1, ProviderEvent::count());
    }

    // --- Withdrawal is unaffected ------------------------------------------

    public function test_consent_withdrawal_still_deletes_immediately(): void
    {
        config([
            'fynnedge.credit.driver' => 'fixture',
            'fynnedge.retention.credit_reports_days' => null,
        ]);

        $user = $this->customer();
        $this->creditReport($user, 1);

        app(ConsentService::class)->grant(
            $user,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );
        app(ConsentService::class)->withdraw(
            $user,
            ConsentType::CreditBureauCheck,
            'privacy_screen',
        );

        // Retention periods govern how long something may be kept while
        // consent stands. Withdrawal is a different thing and does not wait
        // for a schedule.
        $this->assertSame(0, CreditReport::count());
    }
}
