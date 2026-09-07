<?php

namespace App\Console\Commands;

use App\Domain\ProviderEvents\EventProcessingStatus;
use App\Models\ProviderEvent;
use App\Services\ProviderEvents\SubmissionReconciliationService;
use Illuminate\Console\Command;

/**
 * Re-attempts provider events that could not be applied.
 *
 * Module 28 recorded these so an operator could find them, and nothing ever
 * looked at them again. The commonest reason is a race rather than a fault:
 * a provider calling back before FynnEdge has stored the reference the
 * callback quotes. That resolves itself the moment anything tries a second
 * time.
 *
 * Only failures are re-attempted. An ignored event was understood and
 * deliberately not applied, a processed one is done, and a rejected one
 * never authenticated — retrying any of those asks the same question and
 * gets the same answer.
 *
 * Deliberately not scheduled. This mutates customer application state, and
 * turning it into background work that runs unattended is a deployment
 * decision somebody should take on purpose rather than inherit.
 */
class RetryProviderEventsCommand extends Command
{
    protected $signature = 'fynn:provider-events:retry
        {--apply : Re-attempt them. Without this, nothing is retried.}
        {--limit=100 : How many to consider in one run.}';

    protected $description = 'Re-attempt provider events that could not be applied';

    public function handle(SubmissionReconciliationService $reconciliation): int
    {
        $pending = ProviderEvent::query()
            ->where('processing_status', EventProcessingStatus::Failed->value)
            ->orderBy('received_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No provider events are waiting to be re-attempted.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        $resolved = 0;

        foreach ($pending as $record) {
            $before = $record->outcome_reason;

            if (! $apply) {
                $rows[] = [
                    $record->getKey(),
                    $record->provider_code,
                    $record->event_type,
                    $before ?? '—',
                    (string) $record->retry_count,
                    'would retry',
                ];

                continue;
            }

            $after = $reconciliation->retry($record);
            $worked = $after->processingStatus() !== EventProcessingStatus::Failed;

            if ($worked) {
                $resolved++;
            }

            $rows[] = [
                $after->getKey(),
                $after->provider_code,
                $after->event_type,
                $before ?? '—',
                (string) $after->retry_count,
                $worked ? $after->processing_status : 'still failed',
            ];
        }

        $this->table(
            ['Event', 'Provider', 'Type', 'Why it failed', 'Tries', 'Result'],
            $rows,
        );

        if (! $apply) {
            $this->newLine();
            $this->line('Nothing was retried. Re-run with --apply to re-attempt them.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("{$resolved} of {$pending->count()} now applied.");

        if ($resolved < $pending->count()) {
            $this->line(
                'The rest still have nothing to attach to. A high try count '
                .'means the reference has never arrived, which is worth '
                .'looking at rather than retrying again.'
            );
        }

        return self::SUCCESS;
    }
}
