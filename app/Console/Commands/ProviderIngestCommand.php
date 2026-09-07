<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ProviderType;
use App\Domain\Provider\Fixture\FixtureProviderAdapter;
use App\Domain\Provider\Fixture\FixtureProviderNormalizer;
use App\Domain\Provider\IngestionResult;
use App\Domain\Provider\ProviderUnavailable;
use App\Services\Catalogue\CatalogueAuditor;
use App\Services\Provider\ProviderIngestionService;
use Illuminate\Console\Command;

/**
 * Fetches a provider's catalogue and brings it into FynnEdge.
 *
 * NO LIVE PROVIDER IS CONNECTED. The only driver is `fixture`, a fictional
 * provider that exists to prove the boundary works.
 *
 * By default the ingested versions are drafts: a person publishes them after
 * looking. `--publish` skips the waiting but not the checking — the same
 * validation, claim guard and overlap rules apply.
 */
class ProviderIngestCommand extends Command
{
    protected $signature = 'fynn:provider:ingest
        {provider=fixture : the provider driver to run}
        {--publish : publish the ingested versions instead of leaving drafts}
        {--dry-run : report what would change without changing anything}';

    protected $description = 'Ingest a provider catalogue into FynnEdge';

    public function handle(ProviderIngestionService $ingestion, CatalogueAuditor $audit): int
    {
        $driver = (string) $this->argument('provider');

        if ($driver !== 'fixture') {
            $this->error(
                "Unknown provider driver '{$driver}'. No live provider is "
                .'connected to FynnEdge; the only driver is `fixture`.',
            );

            return self::FAILURE;
        }

        $audit->actingFromConsole('console:'.(get_current_user() ?: 'unknown'));

        try {
            $result = $ingestion->ingest(
                adapter: new FixtureProviderAdapter,
                normalizer: new FixtureProviderNormalizer,
                providerName: FixtureProviderAdapter::providerName(),
                providerType: ProviderType::Nbfc,
                publish: (bool) $this->option('publish'),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (ProviderUnavailable $e) {
            // A provider failure is not permission to show fictional
            // products as real, so nothing is substituted and nothing is
            // written. The catalogue keeps whatever it already had.
            $this->error($e->getMessage());
            $this->line('Nothing was changed and no retrieval was recorded.');

            return self::FAILURE;
        }

        $this->report($result);

        return $result->succeeded() ? self::SUCCESS : self::FAILURE;
    }

    private function report(IngestionResult $result): void
    {
        $this->line(
            '<info>'.$result->providerCode.'</info>'
            .($result->dryRun ? ' (dry run — nothing changed)' : '')
        );

        $this->table(
            ['Received', 'Normalized', 'Products created', 'Versions created', 'Unchanged', 'Deactivated'],
            [[
                $result->received,
                $result->normalized,
                $result->created,
                $result->versionsCreated,
                $result->unchanged,
                $result->deactivated,
            ]],
        );

        if ($result->failures !== []) {
            $this->newLine();
            $this->error(
                'Nothing was applied. A feed that is wrong in part cannot be '
                .'trusted in part.',
            );

            $this->table(
                ['Product', 'Stage', 'Reason'],
                array_map(fn (array $f) => [
                    $f['reference'] ?? '(no reference)',
                    $f['stage'],
                    $f['reason'],
                ], $result->failures),
            );

            return;
        }

        if ($result->applied && $result->versionsCreated > 0) {
            $this->newLine();
            $this->line(
                'Ingested versions are drafts unless --publish was given. '
                .'Review them with: <info>fynn:catalogue show <product></info>'
            );
        }
    }
}
