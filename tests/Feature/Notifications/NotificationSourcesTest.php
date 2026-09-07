<?php

namespace Tests\Feature\Notifications;

use App\Domain\Consent\ConsentType;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureDocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureScenario;
use App\Domain\Vault\Malware\MalwareScanner;
use App\Domain\Vault\Malware\ScanVerdict;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The three quieter sources: documents, FynnScore and goals.
 *
 * Each has its own reason to say almost nothing. A document reading
 * finishing is worth one sentence and no extracted figure. A FynnScore
 * recalculates on every profile edit and almost never means anything. A
 * goal moves by a rupee at a time.
 *
 * So most of these tests are about silence, which is the harder thing to
 * get right and the thing a notification centre gets wrong first.
 */
class NotificationSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
        config(['fynnedge.scan.driver' => 'fixture']);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function withIncome(User $user, int $income, int $expenses, int $emi, int $savings): void
    {
        $this->putJson('/api/v1/financial-profile', [
            'monthly_income' => $income,
            'monthly_expenses' => $expenses,
            'existing_emi' => $emi,
            'savings' => $savings,
            'employment_type' => 'salaried',
        ])->assertOk();
    }

    private function upload(string $type = 'income'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, "{$type}.pdf", 'application/pdf', null, true),
            'type' => $type,
        ])->assertCreated()->json('data.id');
    }

    private function scanConsent(User $user): void
    {
        app(ConsentService::class)->grant($user, ConsentType::DocumentProcessing, 'privacy_screen');
    }

    private function scanner(ScanVerdict $verdict): void
    {
        $this->app->instance(MalwareScanner::class, new class($verdict) implements MalwareScanner
        {
            public function __construct(private readonly ScanVerdict $verdict) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function name(): ?string
            {
                return 'test-scanner';
            }

            public function scan(Document $document, string $bytes): ScanVerdict
            {
                return $this->verdict;
            }
        });
    }

    /**
     * A scan provider that says it is real, so the transfer gate applies.
     *
     * With the ordinary fixture nothing leaves FynnEdge, so the gate lets
     * everything through and no safety refusal is reachable — which makes
     * a test of the safety path against the fixture a test of nothing.
     */
    private function realScanProvider(): void
    {
        $this->app->instance(
            DocumentUnderstandingProvider::class,
            new class extends FixtureDocumentUnderstandingProvider
            {
                public function isSample(): bool
                {
                    return false;
                }
            },
        );
    }

    private function types(): array
    {
        return Notification::query()->pluck('type')->all();
    }

    private function ofType(string $type): ?Notification
    {
        return Notification::query()->where('type', $type)->first();
    }

    // --- Documents ---------------------------------------------------------

    public function test_a_finished_reading_is_offered_for_review_and_quotes_nothing(): void
    {
        $user = $this->customer();
        $this->scanConsent($user);
        $this->scanner(ScanVerdict::Clean);
        config(['fynnedge.scan.fixture_scenario' => FixtureScenario::Good->value]);

        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        $item = $this->ofType('document_ready');
        $this->assertNotNull($item);
        $this->assertSame('document', $item->category);
        $this->assertSame("fynnedge://vault/{$id}", $item->deep_link);

        // Evidence, not truth: it asks them to check rather than announcing
        // a figure. And no figure travels — a salary in a notification is a
        // salary on a lock screen.
        $this->assertStringContainsString('before anything is saved', $item->body);
        $this->assertDoesNotMatchRegularExpression('/[0-9]{4,}/', $item->body);
    }

    public function test_a_reading_that_could_not_be_trusted_asks_for_attention(): void
    {
        $user = $this->customer();
        $this->scanConsent($user);
        $this->scanner(ScanVerdict::Clean);
        config(['fynnedge.scan.fixture_scenario' => FixtureScenario::LowConfidence->value]);

        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        $this->assertNotNull($this->ofType('document_needs_review'));
        $this->assertNull($this->ofType('document_ready'));
    }

    public function test_a_document_that_failed_a_safety_check_says_so_without_saying_how(): void
    {
        $user = $this->customer();
        $this->scanConsent($user);
        $this->realScanProvider();
        $this->scanner(ScanVerdict::Infected);

        $id = $this->upload();
        $this->postJson("/api/v1/documents/{$id}/scan")->assertOk();

        $item = $this->ofType('document_safety_issue');
        $this->assertNotNull($item);
        $this->assertStringContainsString('could not accept', $item->body);

        // Nothing a customer cannot act on, and nothing an attacker probing
        // what FynnEdge checks could learn from.
        $whole = strtolower($item->title.' '.$item->body);
        foreach ([
            'malware', 'virus', 'infected', 'scanner', 'test-scanner',
            'verdict', 'quarantine', 'hash', 'storage',
        ] as $internal) {
            $this->assertStringNotContainsString($internal, $whole);
        }
    }

    public function test_a_missing_scanner_is_fynnedges_problem_and_not_the_customers(): void
    {
        $user = $this->customer();
        $this->scanConsent($user);
        $this->realScanProvider();

        // The default: NO MALWARE SCANNER IS CONNECTED. The gate refuses
        // the transfer, and telling a customer their document "could not be
        // accepted" would blame them for an operational gap of FynnEdge's.
        $id = $this->upload();

        /*
         * Refused in the request itself, before anything is queued. The
         * customer is told there and then, synchronously, which is the
         * right channel for an answer to something they just asked for —
         * a notification would be FynnEdge telling them later about a
         * refusal they already saw.
         */
        $this->postJson("/api/v1/documents/{$id}/scan")->assertStatus(409);

        $this->assertNull($this->ofType('document_safety_issue'));
        $this->assertSame([], $this->types());
    }

    // --- FynnScore ---------------------------------------------------------

    public function test_a_first_score_is_not_a_change(): void
    {
        $user = $this->customer();

        // "Your FynnScore changed" is not true of a score that has just
        // come into being, and a customer completing their profile does not
        // need to be told about the screen they are looking at.
        $this->withIncome($user, 150000, 40000, 10000, 600000);

        $this->assertNull($this->ofType('fynnscore_changed'));
    }

    public function test_an_ordinary_edit_says_nothing(): void
    {
        $user = $this->customer();
        $this->withIncome($user, 150000, 40000, 10000, 600000);

        // A few hundred rupees. The score moves; the band does not; the
        // customer's own score screen says exactly what it said before.
        $this->withIncome($user, 150500, 40000, 10000, 600000);

        $this->assertNull($this->ofType('fynnscore_changed'));
    }

    public function test_crossing_a_band_is_worth_saying_and_calls_it_a_fynnscore(): void
    {
        $user = $this->customer();
        $this->withIncome($user, 150000, 40000, 10000, 600000);

        // Stretched: a materially different picture, and a different band
        // on their own score screen.
        $this->withIncome($user, 60000, 50000, 25000, 0);

        $item = $this->ofType('fynnscore_changed');
        $this->assertNotNull($item);
        $this->assertSame('financial_health', $item->category);
        $this->assertSame('fynnedge://fynn-score', $item->deep_link);

        // It is FynnEdge's own reading of what they entered. Never a credit
        // score, a bureau score or a lender's view.
        $whole = strtolower($item->title.' '.$item->body);
        $this->assertStringContainsString('not a credit score', $whole);
        foreach (['cibil', 'bureau', 'lender score', 'approval score'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $whole);
        }

        // And no figure.
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $item->body);
    }

    public function test_moving_back_and_forth_is_told_each_time(): void
    {
        $user = $this->customer();
        $this->withIncome($user, 150000, 40000, 10000, 600000);
        $this->withIncome($user, 60000, 50000, 25000, 0);
        $this->withIncome($user, 150000, 40000, 10000, 600000);

        // A customer whose score falls and recovers has had two things
        // happen to them. Keying on the destination band alone would have
        // told them once.
        $this->assertSame(2, Notification::where('type', 'fynnscore_changed')->count());
    }

    public function test_the_same_recalculation_twice_is_told_once(): void
    {
        $user = $this->customer();
        $this->withIncome($user, 150000, 40000, 10000, 600000);
        $this->withIncome($user, 60000, 50000, 25000, 0);
        $this->withIncome($user, 60000, 50000, 25000, 0);

        $this->assertSame(1, Notification::where('type', 'fynnscore_changed')->count());
    }

    // --- Goals -------------------------------------------------------------

    private function goal(int $target, int $saved): string
    {
        return $this->postJson('/api/v1/goals', [
            'title' => 'Emergency fund',
            'category' => 'personal',
            'target_amount' => $target,
            'saved_amount' => $saved,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');
    }

    public function test_a_goal_below_a_milestone_says_nothing(): void
    {
        $this->customer();
        $this->goal(100000, 10000);

        $this->assertNull($this->ofType('goal_milestone'));
    }

    public function test_passing_a_milestone_is_said_once_however_often_the_goal_is_edited(): void
    {
        $this->customer();
        $id = $this->goal(100000, 10000);

        $update = fn (int $saved) => $this->putJson("/api/v1/goals/{$id}", [
            'title' => 'Emergency fund',
            'category' => 'personal',
            'target_amount' => 100000,
            'saved_amount' => $saved,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertOk();

        $update(50000);
        $update(52000);

        // Down again, and back. A customer who slips below 50% must not be
        // congratulated on reaching 25% — they passed that long ago.
        $update(49000);
        $update(51000);

        $item = $this->ofType('goal_milestone');
        $this->assertNotNull($item);
        $this->assertSame('goal', $item->category);
        $this->assertSame('fynnedge://goals', $item->deep_link);
        $this->assertStringContainsString('Emergency fund', $item->body);

        $this->assertSame(1, Notification::where('type', 'goal_milestone')->count());
    }

    public function test_each_milestone_is_its_own(): void
    {
        $this->customer();
        $id = $this->goal(100000, 10000);

        foreach ([25000, 50000, 75000, 100000] as $saved) {
            $this->putJson("/api/v1/goals/{$id}", [
                'title' => 'Emergency fund',
                'category' => 'personal',
                'target_amount' => 100000,
                'saved_amount' => $saved,
                'target_date' => now()->addYear()->toDateString(),
            ])->assertOk();
        }

        $this->assertSame(4, Notification::where('type', 'goal_milestone')->count());

        // Reaching it reads differently from being on the way.
        $this->assertNotNull(
            Notification::where('title', 'You reached your goal')->first()
        );
    }

    public function test_a_goal_named_like_an_account_number_still_gets_its_milestone(): void
    {
        $this->customer();

        /*
         * Their own label for their own goal. The content rule that refuses
         * long numbers is aimed at FynnEdge interpolating a provider field,
         * not at a customer's own words — and a customer losing a
         * notification because of how they named something is a bug, not a
         * privacy win.
         */
        $this->postJson('/api/v1/goals', [
            'title' => 'Deposit 123456789012',
            'category' => 'personal',
            'target_amount' => 100000,
            'saved_amount' => 60000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $item = $this->ofType('goal_milestone');
        $this->assertNotNull($item);
        $this->assertStringContainsString('Deposit', $item->body);
        $this->assertStringNotContainsString('123456789012', $item->body);
    }
}
