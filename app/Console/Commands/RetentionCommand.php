<?php

namespace App\Console\Commands;

use App\Models\CreditReport;
use App\Models\DocumentScan;
use App\Models\KycVerification;
use App\Models\ProviderEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What FynnEdge is holding, how old it is, and what a configured policy
 * would remove.
 *
 * This command exists because five module reports in a row recorded
 * retention as unsettled, and "unsettled" quietly became "unbounded" — every
 * credit report, extraction and verification obtained since Module 23 is
 * still there. Nobody had decided how long to keep them, so nothing ever
 * removed any.
 *
 * It does not decide the policy. Every period defaults to null and null
 * means keep, so running this today deletes nothing and reports what is
 * held. What it does is make the decision enforceable the day it is taken:
 * setting a period is then a configuration change rather than a project.
 *
 * Consent withdrawal is separate and unaffected — that already deletes
 * immediately and unconditionally. These periods govern how long something
 * may be kept while consent still stands.
 */
class RetentionCommand extends Command
{
    protected $signature = 'fynn:retention
        {--apply : Delete what the configured periods cover. Without this, nothing is deleted.}';

    protected $description = 'Report what FynnEdge retains, and apply any configured retention periods';

    /**
     * What is governed, and which record decides an item's age.
     *
     * The date column matters: a credit report is old from when the bureau
     * answered, not from when the row was written.
     *
     * @return array<string, array{label: string, setting: string, column: string, query: Builder}>
     */
    private function subjects(): array
    {
        return [
            'credit_reports' => [
                'label' => 'Credit reports',
                'setting' => 'credit_reports_days',
                'column' => 'obtained_at',
                'query' => CreditReport::query(),
            ],
            'document_scans' => [
                'label' => 'Document extractions',
                'setting' => 'document_scans_days',
                'column' => 'created_at',
                'query' => DocumentScan::query(),
            ],
            'kyc_verifications' => [
                'label' => 'Identity verifications',
                'setting' => 'kyc_verifications_days',
                'column' => 'created_at',
                'query' => KycVerification::query(),
            ],
            'provider_events' => [
                'label' => 'Provider events',
                'setting' => 'provider_events_days',
                'column' => 'received_at',
                'query' => ProviderEvent::query(),
            ],
        ];
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rows = [];
        $anyConfigured = false;
        $deleted = 0;

        foreach ($this->subjects() as $subject) {
            $days = config('fynnedge.retention.'.$subject['setting']);
            $total = (clone $subject['query'])->count();

            $oldest = (clone $subject['query'])->min($subject['column']);

            if ($days === null) {
                $rows[] = [
                    $subject['label'],
                    (string) $total,
                    $this->age($oldest),
                    'no policy set',
                    '—',
                ];

                continue;
            }

            $anyConfigured = true;
            $cutoff = Carbon::now()->subDays($days);

            $expired = (clone $subject['query'])
                ->where($subject['column'], '<', $cutoff)
                ->count();

            if ($apply && $expired > 0) {
                /*
                 * Deleted one row at a time through the model, so any
                 * cascade or model event a table relies on actually runs.
                 * These are small tables and correctness matters more than
                 * the round trips.
                 */
                (clone $subject['query'])
                    ->where($subject['column'], '<', $cutoff)
                    ->get()
                    ->each(fn ($record) => $record->delete());

                $deleted += $expired;
            }

            $rows[] = [
                $subject['label'],
                (string) $total,
                $this->age($oldest),
                $days.' days',
                $apply ? "deleted {$expired}" : "{$expired} over",
            ];
        }

        $this->table(
            ['Held', 'Rows', 'Oldest', 'Policy', $apply ? 'Applied' : 'Would remove'],
            $rows,
        );

        if (! $anyConfigured) {
            $this->newLine();
            $this->warn('No retention period is configured for anything.');
            $this->line(
                'Nothing was deleted. FynnEdge does not choose a retention '
                .'period for you: how long each of these may be kept is a '
                .'product and legal decision. Set the FYNNEDGE_RETAIN_* '
                .'variables once it is taken.'
            );

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Nothing was deleted. Re-run with --apply to enforce the periods above.');
        } else {
            $this->newLine();
            $this->info("Deleted {$deleted} record(s) under the configured periods.");
        }

        return self::SUCCESS;
    }

    private function age(mixed $oldest): string
    {
        if ($oldest === null) {
            return 'none held';
        }

        return Carbon::parse($oldest)->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE).' old';
    }
}
