<?php

namespace App\Console\Commands;

use App\Models\ApplicationSubmission;
use App\Models\DocumentScan;
use App\Models\KycVerification;
use App\Models\ProviderEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * What needs a person to look at it.
 *
 * Every boundary built since Module 23 records its failures "for an
 * operator" — a role that had no way to find any of them short of writing
 * SQL. That made the honesty operationally useless: a customer stuck in an
 * unconfirmed submission is told to contact support, and nobody at support
 * could have found them.
 *
 * This does not invent a monitoring stack. There is no Slack integration,
 * no pager and no error tracker configured in this project, and adding a
 * fake one would be the same mistake as a fake lender in different clothes.
 * What it gives is something real monitoring can consume: a table for a
 * person, `--json` for a machine, and an exit code that is non-zero when a
 * customer is actually blocked.
 */
class HealthCommand extends Command
{
    protected $signature = 'fynn:health
        {--json : Emit machine-readable output instead of a table.}';

    protected $description = 'Report anything stuck that needs a person to look at it';

    /** A customer cannot proceed and cannot unblock themselves. */
    private const BLOCKING = 'blocking';

    /** Worth a look. Nobody is stuck. */
    private const ATTENTION = 'attention';

    public function handle(): int
    {
        $concerns = array_values(array_filter(array_map(
            fn (array $c) => $this->measure($c),
            $this->definitions(),
        )));

        $blocking = array_values(array_filter(
            $concerns,
            fn (array $c) => $c['severity'] === self::BLOCKING,
        ));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked_at' => Carbon::now()->toIso8601String(),
                'healthy' => $concerns === [],
                'blocking_count' => count($blocking),
                'concerns' => $concerns,
            ], JSON_PRETTY_PRINT));

            return $blocking === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($concerns === []) {
            $this->info('Nothing is waiting on a person.');

            return self::SUCCESS;
        }

        $this->table(
            ['Needs attention', 'Count', 'Oldest', 'Severity', 'Why'],
            array_map(fn (array $c) => [
                $c['label'],
                (string) $c['count'],
                $c['oldest'],
                $c['severity'] === self::BLOCKING ? 'BLOCKING' : 'attention',
                $c['why'],
            ], $concerns),
        );

        if ($blocking !== []) {
            $this->newLine();
            $this->error(
                count($blocking).' of these are blocking a customer who '
                .'cannot resolve it themselves.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Everything that can get stuck, and what being stuck means.
     *
     * @return list<array{label: string, severity: string, why: string, column: string, query: Builder}>
     */
    private function definitions(): array
    {
        return [
            [
                /*
                 * The one state where a customer is genuinely stuck.
                 *
                 * FynnEdge sent an application and never heard back, so the
                 * screen tells them not to send it again and to contact
                 * support. Until somebody checks with the provider, they
                 * cannot proceed and cannot safely retry.
                 */
                'label' => 'Submissions never confirmed',
                'severity' => self::BLOCKING,
                'why' => 'Customer told to contact support; only a person can resolve',
                'column' => 'attempted_at',
                'query' => ApplicationSubmission::query()->where('outcome', 'unknown'),
            ],
            [
                'label' => 'Provider events not applied',
                'severity' => self::ATTENTION,
                'why' => 'Try fynn:provider-events:retry; a high retry count means stuck',
                'column' => 'received_at',
                'query' => ProviderEvent::query()->where('processing_status', 'failed'),
            ],
            [
                /*
                 * Kept apart from other scan failures on purpose.
                 *
                 * A file that failed a safety check is a security signal,
                 * not an outage, and it should not be read alongside
                 * provider timeouts as though it were the same kind of
                 * thing.
                 */
                'label' => 'Documents that failed a safety check',
                'severity' => self::ATTENTION,
                'why' => 'A stored file did not pass malware scanning',
                'column' => 'created_at',
                'query' => DocumentScan::query()
                    ->whereIn('failure_reason', [
                        'document_failed_safety_check',
                        'document_check_inconclusive',
                    ]),
            ],
            [
                'label' => 'Document readings that failed',
                'severity' => self::ATTENTION,
                'why' => 'Customer can retry; a cluster suggests a provider problem',
                'column' => 'created_at',
                'query' => DocumentScan::query()
                    ->where('status', 'failed')
                    ->whereNotIn('failure_reason', [
                        'document_failed_safety_check',
                        'document_check_inconclusive',
                    ]),
            ],
            [
                'label' => 'Identity checks that failed',
                'severity' => self::ATTENTION,
                'why' => 'Customer can retry; a cluster suggests a provider problem',
                'column' => 'created_at',
                'query' => KycVerification::query()->where('status', 'failed'),
            ],
        ];
    }

    /**
     * One concern, or null when there is nothing to report.
     *
     * @param  array{label: string, severity: string, why: string, column: string, query: Builder}  $definition
     * @return array<string, mixed>|null
     */
    private function measure(array $definition): ?array
    {
        $count = (clone $definition['query'])->count();

        if ($count === 0) {
            return null;
        }

        $oldest = (clone $definition['query'])->min($definition['column']);

        return [
            'label' => $definition['label'],
            'severity' => $definition['severity'],
            'why' => $definition['why'],
            'count' => $count,
            'oldest' => $oldest === null
                ? 'unknown'
                : Carbon::parse($oldest)->diffForHumans(syntax: Carbon::DIFF_ABSOLUTE),
            'oldest_at' => $oldest === null
                ? null
                : Carbon::parse($oldest)->toIso8601String(),
        ];
    }
}
