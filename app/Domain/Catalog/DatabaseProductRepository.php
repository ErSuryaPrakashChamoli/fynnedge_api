<?php

namespace App\Domain\Catalog;

use App\Models\CatalogueProduct as ProductRecord;
use App\Models\Provider;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * The catalogue, read from the database.
 *
 * What a customer may be offered is the conjunction of four things, and all
 * four have to hold: the provider is active, the product is active, exactly
 * one published version is in force today, and that version makes a coherent
 * LoanProduct. Anything short of that is excluded — never repaired, never
 * defaulted, never substituted with a neighbouring product.
 *
 * Ambiguity is the one case that does not fail quietly. Two published
 * versions in force on the same day means the catalogue cannot say what this
 * product costs, and choosing between them would price a loan from a record
 * nobody selected. That surfaces as AmbiguousCatalogueException.
 */
class DatabaseProductRepository implements ProductRepository
{
    /** @var array<string, list<LoanProduct>> */
    private array $cache = [];

    /** @return list<LoanProduct> */
    public function all(?string $category = null): array
    {
        $key = $category ?? '*';

        return $this->cache[$key] ??= $this->load($category);
    }

    public function find(string $id): ?LoanProduct
    {
        foreach ($this->all() as $product) {
            if ($product->id === $id) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Whether everything this catalogue serves is sample data.
     *
     * A catalogue holding one live product is not a sample catalogue, even
     * if the rest of it is — the screens that label a whole list have to be
     * told the truth about the strongest claim in it.
     */
    public function isSampleData(): bool
    {
        foreach ($this->all() as $product) {
            if ($product->source()->isLive()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Drops every cached read.
     *
     * Called after a catalogue change. Without it an admin could publish a
     * new version and a request already holding this instance would go on
     * answering with the old one.
     */
    public function forget(): void
    {
        $this->cache = [];
    }

    /** @return list<LoanProduct> */
    private function load(?string $category, ?CarbonInterface $at = null): array
    {
        $at ??= Carbon::now();

        // One query for the products, one for their published versions, one
        // for the providers. Not one query per product, and never the whole
        // version history of everything.
        $records = ProductRecord::query()
            ->where('status', ProductStatus::Active->value)
            ->when($category !== null, fn ($q) => $q->where('category', $category))
            ->whereHas(
                'provider',
                fn ($q) => $q->where('status', Provider::STATUS_ACTIVE),
            )
            ->with([
                'provider',
                'publishedVersions' => fn ($q) => $q
                    ->whereDate('effective_from', '<=', $at)
                    ->where(function ($q) use ($at) {
                        $q->whereNull('effective_until')
                            ->orWhereDate('effective_until', '>', $at);
                    }),
            ])
            ->orderBy('id')
            ->get();

        $products = [];

        foreach ($records as $record) {
            $product = $this->resolve($record, $at);

            if ($product !== null) {
                $products[] = $product;
            }
        }

        return $products;
    }

    private function resolve(ProductRecord $record, CarbonInterface $at): ?LoanProduct
    {
        $inForce = $record->publishedVersions;

        if ($inForce->isEmpty()) {
            return null;
        }

        if ($inForce->count() > 1) {
            throw new AmbiguousCatalogueException(
                $record->public_id,
                sprintf(
                    '%d published versions are in force on %s',
                    $inForce->count(),
                    $at->toDateString(),
                ),
            );
        }

        // Through the domain, so the persistent catalogue and the sample one
        // are held to exactly the same invariants — including the coherence
        // check LoanProduct makes when it is constructed.
        return new CatalogueProduct(
            provider: $record->provider->toData(),
            code: $record->product_code,
            name: $record->display_name,
            category: $record->category,
            status: $record->status(),
            versions: [$inForce->first()->toData()],
            legacyId: $record->public_id,
        )->resolve($at);
    }
}
