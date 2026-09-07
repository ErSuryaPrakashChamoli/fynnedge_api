<?php

namespace Database\Seeders;

use App\Domain\Catalog\CatalogueProduct as ProductData;
use App\Domain\Catalog\MockProductRepository;
use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductStatus;
use App\Models\CatalogueProduct;
use App\Models\Provider;
use App\Services\Catalogue\CatalogueAuditor;
use App\Services\Catalogue\CatalogueService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Puts the six fictional sample products into the persistent catalogue.
 *
 * Every figure is carried across from MockProductRepository unchanged — this
 * moves the records, it does not reprice them. The products keep the
 * `prod_*` identities they have always had, because every application ever
 * made against them records those.
 *
 * They arrive as they exist today: one published version each, effective
 * from a date that predates FynnEdge, source `sample`, provider marked
 * fictional. Nothing here makes an invented product look like a real offer.
 */
class SampleCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        // The Module 17 rule. A production database must not acquire six
        // invented financial products because somebody ran `db:seed`.
        if (app()->environment('production')) {
            throw new RuntimeException(
                'SampleCatalogueSeeder writes fictional products and must not '
                .'run in production. Real products are created through the '
                .'catalogue console commands.',
            );
        }

        /** @var CatalogueService $catalogue */
        $catalogue = app(CatalogueService::class);
        app(CatalogueAuditor::class)->actingFromConsole('seeder:sample-catalogue');

        foreach (app(MockProductRepository::class)->catalogueProducts() as $sample) {
            $this->seedProduct($catalogue, $sample);
        }
    }

    private function seedProduct(CatalogueService $catalogue, ProductData $sample): void
    {
        $providerData = $sample->provider;

        $provider = Provider::where('code', $providerData->code)->first()
            ?? $catalogue->createProvider(
                code: $providerData->code,
                name: $providerData->name,
                type: $providerData->type,
                isSample: $providerData->isSample,
            );

        if (CatalogueProduct::where('public_id', $sample->id())->exists()) {
            return;
        }

        $product = $catalogue->createProduct(
            provider: $provider,
            productCode: $sample->code,
            displayName: $sample->name,
            category: $sample->category,
            // The identity these products already have everywhere else.
            publicId: $sample->id(),
        );

        $version = $sample->versions[0];

        $draft = $catalogue->createDraftVersion($product, [
            'effective_from' => $version->effectiveFrom,
            'effective_until' => $version->effectiveUntil,
            'interest_rate' => $version->interestRate,
            'processing_fee_percent' => $version->processingFeePercent,
            'processing_fee_cap' => $version->processingFeeCap?->toDecimalString(),
            'prepayment_charge' => $version->prepaymentCharge,
            'min_amount' => $version->minAmount->toDecimalString(),
            'max_amount' => $version->maxAmount->toDecimalString(),
            'min_tenure_months' => $version->minTenureMonths,
            'max_tenure_months' => $version->maxTenureMonths,
            'min_monthly_income' => $version->minMonthlyIncome?->toDecimalString(),
            'min_age' => $version->minAge,
            'max_age_at_maturity' => $version->maxAgeAtMaturity,
            'fynn_trust' => $version->fynnTrust,
            'trust_breakdown' => $version->trustBreakdown,
            'lender_tag' => $version->lenderTag,
            'suited_reasons' => $version->suitedReasons,
            'considerations' => $version->considerations,
            'conditions' => $version->conditions,
            'source_type' => ProductSourceType::Sample->value,
            'source_reference' => null,
            'source_retrieved_at' => null,
        ]);

        $catalogue->publishVersion($draft);
        $catalogue->setProductStatus($product, ProductStatus::Active);
    }
}
