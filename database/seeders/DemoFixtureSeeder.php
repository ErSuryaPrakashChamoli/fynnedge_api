<?php

namespace Database\Seeders;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Consent\ConsentSource;
use App\Domain\Consent\ConsentStatus;
use App\Domain\Consent\ConsentType;
use App\Domain\Enums\EmploymentType;
use App\Domain\Enums\GoalCategory;
use App\Domain\Enums\Intent;
use App\Domain\Match\MatchCategory;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationType;
use App\Domain\Support\SupportCaseStatus;
use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportPriority;
use App\Domain\Vault\DocumentStatus;
use App\Domain\Vault\DocumentType;
use App\Models\LoanApplication;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invented customers, so the admin panel has something to show.
 *
 * Every record here is made up. That is stated in the data itself and not
 * only in this comment: each customer's name begins "Demo", their mobile
 * numbers are in a reserved block, and every application reference starts
 * DEMO-. Somebody looking at a row in the panel can tell what it is without
 * being told, which is the only labelling that survives a screenshot.
 *
 * Deterministic and idempotent — the same customers with the same figures
 * every time, keyed on mobile number, so running it twice changes nothing
 * and a screenshot taken today matches one taken next week. No Faker:
 * random names in a lending panel look exactly like real ones.
 *
 * Opt-in. DatabaseSeeder does not call this, and it refuses outright in
 * production, where invented loan applications in the customer table would
 * be indistinguishable from the real thing to every report that followed.
 */
class DemoFixtureSeeder extends Seeder
{
    /**
     * Reserved for documentation and examples, so these can never collide
     * with a number a real person could be reached on.
     */
    private const MOBILES = [
        'approved' => '9000000101',
        'in_review' => '9000000102',
        'declined' => '9000000103',
        'starting' => '9000000104',
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'DemoFixtureSeeder writes invented customers, applications and '
                .'support cases. It must never run in production.',
            );
        }

        $this->seedApproved();
        $this->seedInReview();
        $this->seedDeclined();
        $this->seedJustStarting();

        $this->command?->info('Demo fixtures seeded — 4 invented customers, all labelled "Demo".');
    }

    /**
     * A customer whose application was approved, with the trail behind it:
     * consents, documents, an application, its events and a resolved case.
     */
    private function seedApproved(): void
    {
        $user = $this->customer(
            self::MOBILES['approved'],
            'Demo Ananya Rao',
            'Bengaluru',
            34,
            Intent::NeedLoan,
            GoalCategory::Business,
            ['monthly_income' => 145000, 'monthly_expenses' => 52000, 'existing_emi' => 18000, 'savings' => 640000, 'employment_type' => EmploymentType::Salaried],
        );

        $this->grantConsents($user, [
            ConsentType::ServiceTerms,
            ConsentType::PrivacyNotice,
            ConsentType::DocumentProcessing,
            ConsentType::IdentityVerification,
            ConsentType::LenderDataSharing,
        ]);

        $this->document($user, DocumentType::Identity, 'Demo PAN card');
        $this->document($user, DocumentType::Income, 'Demo salary slip — March');
        $this->document($user, DocumentType::Bank, 'Demo bank statement — Q1');

        $application = $this->application($user, [
            'reference' => 'DEMO-APP-1001',
            'product_name' => 'Demo Personal Loan — Standard',
            'lender' => 'Demo Finance (sample)',
            'amount' => 800000,
            'tenure_months' => 48,
            'interest_rate' => 12.500,
            'emi' => 21_314.00,
            'status' => ApplicationStatus::Approved,
        ]);

        $this->events($application, [
            ['Application started', 'The customer began an application from FynnMatch.', 12],
            ['Documents attached', 'Identity, income and bank statement chosen from the vault.', 10],
            ['Submitted to lender', 'Sent for a decision.', 8],
            ['Under review', 'The lender acknowledged the application.', 6],
            ['Approved', 'Approved at the requested amount.', 2],
        ]);

        $this->notify($user, NotificationType::ApplicationApproved, NotificationCategory::Application,
            'Your application was approved', 'Demo Finance approved ₹8,00,000 over 48 months.', read: true);

        $case = $this->supportCase($user, SupportCategory::Application,
            'When will the money arrive?',
            'Demo case. The application shows approved — what happens next?');
        $this->message($case, SupportCaseMessage::AUTHOR_CUSTOMER, $user->id,
            'Demo case. The application shows approved — what happens next?');
        $this->message($case, SupportCaseMessage::AUTHOR_ADVISOR, 1,
            'Demo reply. Disbursal usually follows within two working days.');
        $case->forceFill(['status' => 'resolved', 'resolved_at' => now()->subDays(1)])->save();
    }

    private function seedInReview(): void
    {
        $user = $this->customer(
            self::MOBILES['in_review'],
            'Demo Rohit Menon',
            'Pune',
            41,
            Intent::NeedLoan,
            GoalCategory::Home,
            ['monthly_income' => 98000, 'monthly_expenses' => 44000, 'existing_emi' => 26000, 'savings' => 210000, 'employment_type' => EmploymentType::Business],
        );

        $this->grantConsents($user, [ConsentType::ServiceTerms, ConsentType::PrivacyNotice, ConsentType::DocumentProcessing]);
        $this->document($user, DocumentType::Income, 'Demo income proof');

        $application = $this->application($user, [
            'reference' => 'DEMO-APP-1002',
            'product_name' => 'Demo Home Loan — Long tenure',
            'lender' => 'Demo Housing (sample)',
            'amount' => 3500000,
            'tenure_months' => 180,
            'interest_rate' => 9.250,
            'emi' => 36_020.00,
            'status' => ApplicationStatus::UnderReview,
        ]);

        $this->events($application, [
            ['Application started', 'Begun from the home screen.', 5],
            ['Submitted to lender', 'Sent for a decision.', 3],
            ['Under review', 'Awaiting the lender.', 2],
        ]);

        $this->notify($user, NotificationType::ApplicationUnderReview, NotificationCategory::Application,
            'Your application is being reviewed', 'Demo Housing is reviewing your application.');

        $case = $this->supportCase($user, SupportCategory::SubmissionStatus,
            'How long does review take?',
            'Demo case. It has been two days since submission.');
        $this->message($case, SupportCaseMessage::AUTHOR_CUSTOMER, $user->id,
            'Demo case. It has been two days since submission.');
    }

    private function seedDeclined(): void
    {
        $user = $this->customer(
            self::MOBILES['declined'],
            'Demo Kavya Iyer',
            'Chennai',
            29,
            Intent::NeedLoan,
            GoalCategory::Education,
            ['monthly_income' => 46000, 'monthly_expenses' => 31000, 'existing_emi' => 12000, 'savings' => 38000, 'employment_type' => EmploymentType::Salaried],
        );

        $this->grantConsents($user, [ConsentType::ServiceTerms, ConsentType::PrivacyNotice]);

        $application = $this->application($user, [
            'reference' => 'DEMO-APP-1003',
            'product_name' => 'Demo Personal Loan — Standard',
            'lender' => 'Demo Finance (sample)',
            'amount' => 500000,
            'tenure_months' => 36,
            'interest_rate' => 15.000,
            'emi' => 17_333.00,
            'status' => ApplicationStatus::Declined,
        ]);

        $this->events($application, [
            ['Application started', 'Begun from FynnMatch.', 20],
            ['Submitted to lender', 'Sent for a decision.', 18],
            ['Declined', 'The lender declined. Existing obligations were the stated reason.', 15],
        ]);

        $this->notify($user, NotificationType::ApplicationDeclined, NotificationCategory::Application,
            'Your application was not approved', 'Demo Finance did not approve this application.');
    }

    /** A customer part way through, so the panel shows an incomplete state. */
    private function seedJustStarting(): void
    {
        $user = $this->customer(
            self::MOBILES['starting'],
            'Demo Sanjay Pillai',
            'Kochi',
            37,
            Intent::Exploring,
            GoalCategory::Car,
            ['monthly_income' => 72000, 'monthly_expenses' => 33000, 'existing_emi' => 0, 'savings' => 155000, 'employment_type' => EmploymentType::SelfEmployed],
        );

        $this->grantConsents($user, [ConsentType::ServiceTerms, ConsentType::PrivacyNotice]);

        $this->application($user, [
            'reference' => 'DEMO-APP-1004',
            'product_name' => 'Demo Vehicle Loan',
            'lender' => 'Demo Motors Finance (sample)',
            'amount' => 650000,
            'tenure_months' => 60,
            'interest_rate' => 11.000,
            'emi' => 14_133.00,
            'status' => ApplicationStatus::Started,
        ]);

        $this->notify($user, NotificationType::ApplicationStarted, NotificationCategory::Application,
            'You started an application', 'Pick up where you left off.');
    }

    // -----------------------------------------------------------------
    // Builders
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $financial */
    private function customer(
        string $mobile,
        string $name,
        string $city,
        int $age,
        Intent $intent,
        GoalCategory $goal,
        array $financial,
    ): User {
        $user = User::updateOrCreate(['mobile' => $mobile], ['name' => $name]);
        $user->ensureProfiles();

        $user->customerProfile()->update([
            'full_name' => $name,
            'age' => $age,
            'city' => $city,
            'email' => Str::of($name)->after('Demo ')->replace(' ', '.')->lower().'@demo.fynnedge.test',
            'intent' => $intent->value,
            'primary_goal' => $goal->value,
            'onboarding_completed_at' => now()->subMonths(3),
        ]);

        $user->financialProfile()->update([
            'monthly_income' => $financial['monthly_income'],
            'monthly_expenses' => $financial['monthly_expenses'],
            'existing_emi' => $financial['existing_emi'],
            'savings' => $financial['savings'],
            'other_obligations' => 0,
            'employment_type' => $financial['employment_type']->value,
        ]);

        $user->goals()->updateOrCreate(
            ['title' => 'Demo goal — '.ucfirst($goal->value)],
            [
                'category' => $goal->value,
                'target_amount' => 1200000,
                'target_date' => now()->addYears(3),
                'saved_amount' => 240000,
                'status' => 'active',
            ],
        );

        return $user->refresh();
    }

    /** @param array<int, ConsentType> $types */
    private function grantConsents(User $user, array $types): void
    {
        foreach ($types as $type) {
            if (! $type->isAvailable()) {
                continue;
            }

            $user->consents()->firstOrCreate(
                ['type' => $type->value, 'version' => $type->currentVersion()],
                [
                    'status' => ConsentStatus::Granted->value,
                    'granted_at' => now()->subMonths(2),
                    'source' => ConsentSource::Onboarding->value,
                ],
            );
        }
    }

    private function document(User $user, DocumentType $type, string $name): void
    {
        $user->documents()->firstOrCreate(
            ['display_name' => $name],
            [
                'type' => $type->value,
                'original_filename' => Str::slug($name).'.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 184_320,
                'storage_disk' => 'local',
                // No file is written. The vault endpoint reports a missing
                // document rather than serving bytes, which is the honest
                // outcome for a record that describes a file nobody uploaded.
                'storage_key' => 'documents/demo/'.Str::slug($name).'.pdf',
                'status' => DocumentStatus::Uploaded->value,
            ],
        );
    }

    /** @param array<string, mixed> $attributes */
    private function application(User $user, array $attributes): LoanApplication
    {
        /** @var LoanApplication $application */
        $application = $user->applications()->updateOrCreate(
            ['reference' => $attributes['reference']],
            [
                'product_id' => 'prod_demo',
                'product_name' => $attributes['product_name'],
                'lender' => $attributes['lender'],
                'product_category' => 'personal_loan',
                'amount' => $attributes['amount'],
                'tenure_months' => $attributes['tenure_months'],
                'interest_rate' => $attributes['interest_rate'],
                'emi' => $attributes['emi'],
                'total_payable' => $attributes['emi'] * $attributes['tenure_months'],
                'total_interest' => ($attributes['emi'] * $attributes['tenure_months']) - $attributes['amount'],
                'processing_fee' => round($attributes['amount'] * 0.01, 2),
                'match_category' => ($attributes['match_category'] ?? MatchCategory::Good)->value,
                'status' => $attributes['status']->value,
            ],
        );

        return $application;
    }

    /** @param array<int, array{0: string, 1: string, 2: int}> $events */
    private function events(LoanApplication $application, array $events): void
    {
        foreach ($events as [$label, $detail, $daysAgo]) {
            $application->events()->firstOrCreate(
                ['label' => $label],
                [
                    'detail' => $detail,
                    'source' => 'fynnedge',
                    'occurred_at' => now()->subDays($daysAgo),
                ],
            );
        }
    }

    private function notify(
        User $user,
        NotificationType $type,
        NotificationCategory $category,
        string $title,
        string $body,
        bool $read = false,
    ): void {
        $user->notifications()->firstOrCreate(
            ['title' => $title],
            [
                'type' => $type->value,
                'category' => $category->value,
                'body' => $body,
                'idempotency_key' => 'demo:'.$user->id.':'.$type->value,
                'read_at' => $read ? now()->subHours(6) : null,
            ],
        );
    }

    private function supportCase(
        User $user,
        SupportCategory $category,
        string $subject,
        string $description,
    ): SupportCase {
        /** @var ?SupportCase $existing */
        $existing = $user->supportCases()->where('subject', $subject)->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var SupportCase $case */
        $case = $user->supportCases()->make([
            'reference' => 'DEMO-SUP-'.$user->id.'01',
            'category' => $category->value,
            'subject' => $subject,
            'description' => $description,
            'idempotency_key' => 'demo:case:'.$user->id,
        ]);

        // `status` and `priority` are guarded — a customer opening a case
        // does not get to say it is urgent or already resolved — so the
        // seeder sets the opening values directly.
        $case->forceFill([
            'status' => SupportCaseStatus::Open->value,
            'priority' => SupportPriority::Normal->value,
        ])->save();

        return $case;
    }

    private function message(SupportCase $case, string $authorType, int $authorId, string $body): void
    {
        if ($case->messages()->where('body', $body)->exists()) {
            return;
        }

        $message = $case->messages()->make(['body' => $body]);
        // author_type is guarded on purpose — it is the field a customer
        // would set to speak as FynnEdge — so the seeder writes it directly.
        $message->forceFill(['author_type' => $authorType, 'author_id' => $authorId])->save();
    }
}
