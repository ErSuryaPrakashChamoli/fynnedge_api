<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\AdminUser;
use Database\Seeders\DemoFixtureSeeder;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every page the panel offers actually renders.
 *
 * Discovered rather than listed, so a resource added later is covered the
 * day it is added — and rendered with rows in the tables, because that
 * is where the failures live: a column naming a relationship that does
 * not exist, a badge whose match arm misses an enum case, or a money
 * format over a null all render fine on an empty table and throw on the
 * first real row.
 */
class PanelRendersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rows in every table the panel lists, so the columns and badges
        // are exercised rather than merely constructed.
        $this->seed(DemoFixtureSeeder::class);
    }

    private function superAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Render Probe',
            'email' => 'render@fynnedge.test',
            'password' => 'CorrectHorseBattery1',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    /**
     * Discovered from the filesystem, because a data provider runs before
     * the application is booted and the panel's registry does not exist
     * yet. Same effect: a resource added later is covered without anybody
     * remembering to list it here.
     *
     * @return array<string, array{class-string<resource>}>
     */
    public static function resources(): array
    {
        $cases = [];

        foreach (glob(__DIR__.'/../../../app/Filament/Resources/*/*Resource.php') ?: [] as $file) {
            $directory = basename(dirname($file));
            $class = 'App\\Filament\\Resources\\'.$directory.'\\'.basename($file, '.php');

            $cases[basename($file, '.php')] = [$class];
        }

        return $cases;
    }

    /** @param class-string<resource> $resource */
    #[DataProvider('resources')]
    public function test_the_list_page_renders(string $resource): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get($resource::getUrl('index'))->assertSuccessful();
    }

    public function test_the_dashboard_renders_with_its_widgets(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get('/admin')->assertSuccessful();
    }
}
