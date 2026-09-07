<?php

namespace App\Console\Commands;

use App\Domain\Catalog\ProductStatus;
use App\Models\CatalogueAuditEvent;
use App\Models\CatalogueProduct;
use App\Models\ProductVersion;
use App\Models\Provider;
use App\Services\Catalogue\CatalogueAuditor;
use App\Services\Catalogue\CatalogueException;
use App\Services\Catalogue\CatalogueService;
use Illuminate\Console\Command;

/**
 * Catalogue administration.
 *
 * The console is the administration surface, and that is a decision rather
 * than an omission. The only way to hold a token in this API is to verify an
 * OTP sent to a mobile number, and everyone who does is a customer; there is
 * no administrator identity to authorize an admin endpoint against. Building
 * a second authentication path to serve a handful of operations would add
 * more attack surface than it removes.
 *
 * So authorization here is server access, which is the strongest boundary
 * available, and no customer token can reach any of it.
 *
 * Publishing and activating are separate steps on purpose: a published
 * version is a set of terms, and activating is the decision to offer them.
 */
class CatalogueCommand extends Command
{
    protected $signature = 'fynn:catalogue
        {action : list|show|audit|activate|deactivate|publish|clone|close|provider-status}
        {target? : product public id, version id, or provider code}
        {--rate= : new interest rate, with clone}
        {--fee= : new processing fee percent, with clone}
        {--from= : effective_from for the clone, YYYY-MM-DD}
        {--until= : effective_until for the version being replaced}
        {--status= : active|inactive, with provider-status}';

    protected $description = 'Inspect and administer the FynnEdge product catalogue';

    public function handle(CatalogueService $catalogue, CatalogueAuditor $audit): int
    {
        $audit->actingFromConsole('console:'.(get_current_user() ?: 'unknown'));

        try {
            return match ($this->argument('action')) {
                'list' => $this->list(),
                'show' => $this->show(),
                'audit' => $this->auditTrail(),
                'activate' => $this->setStatus($catalogue, ProductStatus::Active),
                'deactivate' => $this->setStatus($catalogue, ProductStatus::Inactive),
                'publish' => $this->publish($catalogue),
                'clone' => $this->cloneVersion($catalogue),
                'close' => $this->closeVersion($catalogue),
                'provider-status' => $this->providerStatus($catalogue),
                default => $this->refuse('Unknown action.'),
            };
        } catch (CatalogueException $e) {
            // What to do instead, not a stack trace.
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function list(): int
    {
        $rows = CatalogueProduct::with(['provider', 'versions'])
            ->orderBy('id')
            ->get()
            ->map(function (CatalogueProduct $p) {
                $current = $p->publishedVersions()
                    ->orderByDesc('effective_from')
                    ->first();

                return [
                    $p->public_id,
                    $p->provider->code.($p->provider->isActive() ? '' : ' (inactive)'),
                    $p->status,
                    $current?->interest_rate ?? '—',
                    $current?->effective_from?->toDateString() ?? '—',
                    $current?->effective_until?->toDateString() ?? 'open',
                    $current?->source_type ?? '—',
                    $p->versions->count().' ('.$p->publishedVersions()->count().' published)',
                ];
            });

        $this->table(
            ['Product', 'Provider', 'Status', 'Rate', 'From', 'Until', 'Source', 'Versions'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function show(): int
    {
        $product = $this->product();

        $this->line("<info>{$product->public_id}</info> — {$product->display_name}");
        $this->line("provider: {$product->provider->code} ({$product->provider->status})");
        $this->line("category: {$product->category}   status: {$product->status}");
        $this->newLine();

        $this->table(
            ['Version', 'State', 'From', 'Until', 'Rate', 'Fee %', 'Trust', 'Source'],
            $product->versions()->orderBy('id')->get()->map(fn (ProductVersion $v) => [
                $v->id,
                $v->isPublished() ? 'published' : 'draft',
                $v->effective_from->toDateString(),
                $v->effective_until?->toDateString() ?? 'open',
                $v->interest_rate,
                $v->processing_fee_percent,
                $v->fynn_trust,
                $v->source_type,
            ]),
        );

        return self::SUCCESS;
    }

    private function auditTrail(): int
    {
        $product = $this->product();
        $versionIds = $product->versions()->pluck('id');

        $events = CatalogueAuditEvent::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('entity_type', 'product')
                    ->where('entity_id', $product->id))
                ->orWhere(fn ($q) => $q->where('entity_type', 'version')
                    ->whereIn('entity_id', $versionIds)))
            ->orderBy('id')
            ->get();

        $this->table(
            ['When', 'Actor', 'Action', 'Entity'],
            $events->map(fn (CatalogueAuditEvent $e) => [
                $e->created_at->toDateTimeString(),
                $e->actor_label,
                $e->action,
                $e->entity_type.'#'.$e->entity_id,
            ]),
        );

        return self::SUCCESS;
    }

    private function setStatus(CatalogueService $catalogue, ProductStatus $status): int
    {
        $product = $catalogue->setProductStatus($this->product(), $status);
        $this->info("{$product->public_id} is now {$product->status}.");

        return self::SUCCESS;
    }

    private function publish(CatalogueService $catalogue): int
    {
        $version = ProductVersion::findOrFail((int) $this->argument('target'));
        $published = $catalogue->publishVersion($version);

        $this->info(sprintf(
            'Version %d published, effective %s.',
            $published->id,
            $published->effective_from->toDateString(),
        ));

        return self::SUCCESS;
    }

    /**
     * The correction path: copy a published version into a draft, change
     * what needs changing, and close the old one on the same day the new one
     * begins so the two never overlap.
     */
    private function cloneVersion(CatalogueService $catalogue): int
    {
        $source = ProductVersion::findOrFail((int) $this->argument('target'));
        $from = $this->option('from');

        if ($from === null) {
            $this->error('--from is required: a new version needs a start date.');

            return self::FAILURE;
        }

        $changes = ['effective_from' => $from, 'effective_until' => null];

        if ($this->option('rate') !== null) {
            $changes['interest_rate'] = (float) $this->option('rate');
        }

        if ($this->option('fee') !== null) {
            $changes['processing_fee_percent'] = (float) $this->option('fee');
        }

        $draft = $catalogue->cloneToDraft($source, $changes);

        $this->info("Draft version {$draft->id} created from version {$source->id}.");
        $this->line(
            'Close the version it replaces before publishing, or the two '
            .'overlap and the catalogue will refuse to say what the product '
            .'costs:'
        );
        $this->line("  fynn:catalogue close {$source->id} --until={$from}");
        $this->line("  fynn:catalogue publish {$draft->id}");

        return self::SUCCESS;
    }

    /**
     * Ends a published version on a given day.
     *
     * The one change a published version is allowed, and it is not a change
     * to its terms: those stay exactly as they were for every application
     * made under them. This only says when they stopped applying.
     */
    private function closeVersion(CatalogueService $catalogue): int
    {
        $version = ProductVersion::findOrFail((int) $this->argument('target'));
        $until = $this->option('until');

        if ($until === null) {
            $this->error('--until is required: a version has to end on a day.');

            return self::FAILURE;
        }

        $closed = $catalogue->closeVersion($version, $until);

        $this->info(sprintf(
            'Version %d now ends on %s. Its terms are unchanged.',
            $closed->id,
            $closed->effective_until->toDateString(),
        ));

        return self::SUCCESS;
    }

    private function providerStatus(CatalogueService $catalogue): int
    {
        $provider = Provider::where('code', $this->argument('target'))->firstOrFail();
        $updated = $catalogue->setProviderStatus(
            $provider,
            (string) $this->option('status'),
        );

        $this->info("Provider {$updated->code} is now {$updated->status}.");

        return self::SUCCESS;
    }

    private function product(): CatalogueProduct
    {
        return CatalogueProduct::with('provider')
            ->where('public_id', $this->argument('target'))
            ->firstOrFail();
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
