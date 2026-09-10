<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel's stylesheet and scripts are actually on disk.
 *
 * PanelRendersTest asks whether each page returns 200 and renders the right
 * HTML, and it passed for a panel that arrived in the browser as unstyled
 * black text on white: the markup was correct and every asset it referenced
 * was a 404. A page can be entirely right and entirely unusable.
 *
 * Filament ships compiled CSS and JS inside the package and they have to be
 * copied into `public/` by `php artisan filament:assets`. That is a build
 * step, so it is missing on a fresh checkout, after `composer install` on a
 * deployment, and any time the package is upgraded — three occasions when
 * nothing else fails.
 */
class PanelAssetsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Read out of the rendered page rather than hard-coded, so an asset
     * Filament starts shipping is covered without anybody adding it here.
     *
     * @return list<string>
     */
    private function assetPathsOn(string $html): array
    {
        preg_match_all('/(?:href|src)="([^"]+)"/', $html, $matches);

        $paths = [];

        foreach ($matches[1] as $url) {
            $path = parse_url($url, PHP_URL_PATH);

            if ($path === null || $path === false) {
                continue;
            }

            // Only what this application serves off disk. Livewire's own
            // endpoints are routes, not files, and are covered elsewhere.
            if (! preg_match('#^/(css|js|fonts)/filament/#', $path)) {
                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    public function test_the_login_page_references_assets_that_exist(): void
    {
        $html = $this->get('/admin/login')->assertSuccessful()->getContent();

        $paths = $this->assetPathsOn($html);

        $this->assertNotEmpty(
            $paths,
            'The login page referenced no Filament assets at all, which means '
            .'this test is no longer checking anything.',
        );

        foreach ($paths as $path) {
            $this->assertFileExists(
                public_path(ltrim($path, '/')),
                "The panel asks the browser for {$path} and it is not in public/. "
                .'Run `php artisan filament:assets`.',
            );
        }
    }

    public function test_the_dashboard_references_assets_that_exist(): void
    {
        $admin = AdminUser::create([
            'name' => 'Asset Probe',
            'email' => 'assets@fynnedge.test',
            'password' => 'CorrectHorseBattery1',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        $html = $this->get('/admin')->assertSuccessful()->getContent();

        foreach ($this->assetPathsOn($html) as $path) {
            $this->assertFileExists(
                public_path(ltrim($path, '/')),
                "The dashboard asks the browser for {$path} and it is not in public/. "
                .'Run `php artisan filament:assets`.',
            );
        }
    }

    /**
     * The stylesheet is the whole difference between a working panel and
     * unstyled text, so it is worth asserting it is a stylesheet rather
     * than an empty file somebody created to make a check pass.
     */
    public function test_the_stylesheet_is_not_empty(): void
    {
        $css = public_path('css/filament/filament/app.css');

        $this->assertFileExists($css, 'Run `php artisan filament:assets`.');

        $this->assertGreaterThan(
            10_000,
            filesize($css),
            'The panel stylesheet is implausibly small.',
        );
    }
}
