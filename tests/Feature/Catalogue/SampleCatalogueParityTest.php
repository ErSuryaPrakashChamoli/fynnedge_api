<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Catalog\DatabaseProductRepository;
use App\Domain\Catalog\MockProductRepository;
use App\Domain\Catalog\ProductRepository;
use App\Models\CatalogueProduct;
use App\Models\Provider;
use Database\Seeders\SampleCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The six sample products, moved into the persistent catalogue.
 *
 * Module 21 restructures where they live. It must not reprice them: a
 * migration that quietly changes a rate is worse than no migration, because
 * every parity fixture and every existing application was built on those
 * numbers.
 */
class SampleCatalogueParityTest extends TestCase
{
    use RefreshDatabase;

    private function seedCatalogue(): void
    {
        (new SampleCatalogueSeeder)->run();
    }

    public function test_the_persisted_catalogue_is_financially_identical(): void
    {
        $this->seedCatalogue();

        $sample = collect(app(MockProductRepository::class)->all())
            ->map(fn ($p) => $p->toArray())
            ->keyBy('id');

        $persisted = collect((new DatabaseProductRepository)->all())
            ->map(fn ($p) => $p->toArray())
            ->keyBy('id');

        $this->assertCount(6, $sample);
        $this->assertSame(
            $sample->keys()->all(),
            $persisted->keys()->all(),
            'the persisted catalogue holds different products',
        );

        foreach ($sample as $id => $expected) {
            $this->assertSame(
                $expected,
                $persisted[$id],
                "product {$id} changed on the way into the database",
            );
        }
    }

    public function test_the_six_keep_the_identities_applications_record(): void
    {
        $this->seedCatalogue();

        foreach (CatalogueProduct::all() as $product) {
            // Not provider_code:product_code, because every application ever
            // made against these records the `prod_*` string.
            $this->assertStringStartsWith('prod_', $product->public_id);
            $this->assertNotSame(
                CatalogueProduct::composeId(
                    $product->provider->code,
                    $product->product_code,
                ),
                $product->public_id,
            );
        }
    }

    public function test_every_seeded_provider_is_marked_fictional(): void
    {
        $this->seedCatalogue();

        $this->assertSame(6, Provider::count());

        foreach (Provider::all() as $provider) {
            $this->assertTrue($provider->is_sample, $provider->code);
            $this->assertTrue($provider->isActive());
        }

        foreach ((new DatabaseProductRepository)->all() as $product) {
            $this->assertFalse($product->source()->isLive(), $product->id);
            $this->assertTrue($product->toArray()['is_sample_data']);
        }

        $this->assertTrue((new DatabaseProductRepository)->isSampleData());
    }

    public function test_seeding_twice_creates_nothing_extra(): void
    {
        $this->seedCatalogue();
        $this->seedCatalogue();

        $this->assertSame(6, CatalogueProduct::count());
        $this->assertSame(6, Provider::count());
    }

    public function test_the_sample_catalogue_cannot_be_seeded_into_production(): void
    {
        // The Module 17 rule: `db:seed` is an ordinary deployment step, and
        // six invented financial products must not arrive because of it.
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->seedCatalogue();
    }

    public function test_the_default_driver_is_the_sample_catalogue(): void
    {
        // A deployment that has never populated the catalogue gets the
        // fictional six and the labelling that goes with them, rather than
        // an empty shop — and never a silent fallback in the other
        // direction, which is how invented pricing reaches someone who was
        // promised real pricing.
        $this->assertSame('sample', config('fynnedge.catalogue.driver'));
        $this->assertInstanceOf(
            MockProductRepository::class,
            app(ProductRepository::class),
        );
    }
}
