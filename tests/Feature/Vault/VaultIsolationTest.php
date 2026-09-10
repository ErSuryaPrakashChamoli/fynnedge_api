<?php

namespace Tests\Feature\Vault;

use App\Services\DocumentVaultService;
use Tests\TestCase;

/**
 * The customer document vault is not reachable by any route but its own.
 *
 * Found by reviewing the route table rather than by a failing test, which
 * is the point of writing these down: Laravel's default `local` disk was
 * configured with `serve => true`, registering public GET and PUT routes at
 * storage/{path} rooted at app/private — one directory above the vault at
 * app/private/documents.
 *
 * Nothing was actually reachable: Laravel refuses those requests without a
 * signed URL and FynnEdge never mints one. But that is a framework default,
 * not a decision this project made, and someone adding `visibility =>
 * public` to that disk for an unrelated reason would have published every
 * customer's documents without touching a line of vault code.
 *
 * These assertions are the durable part. They fail if the serving is
 * switched back on, if the vault is moved underneath a served disk, or if
 * the vault's own disk is ever made public.
 */
class VaultIsolationTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function localDisks(): array
    {
        return array_filter(
            config('filesystems.disks'),
            fn ($disk) => ($disk['driver'] ?? null) === 'local',
        );
    }

    public function test_the_vault_disk_is_private_and_is_not_served(): void
    {
        $vault = config('filesystems.disks.'.DocumentVaultService::DISK);

        $this->assertNotNull($vault, 'the vault disk is not configured');
        $this->assertSame('private', $vault['visibility'] ?? null);
        $this->assertFalse(
            $vault['serve'] ?? false,
            'the vault disk is served by the web server',
        );
        $this->assertArrayNotHasKey(
            'url',
            $vault,
            'the vault disk has a public URL',
        );
    }

    public function test_no_served_disk_contains_the_vault(): void
    {
        $vaultRoot = $this->normalise(
            config('filesystems.disks.'.DocumentVaultService::DISK.'.root'),
        );

        $this->assertNotSame('', $vaultRoot, 'the vault disk has no root.');

        $offenders = [];

        foreach ($this->localDisks() as $name => $disk) {
            if ($name === DocumentVaultService::DISK) {
                continue;
            }

            if (! ($disk['serve'] ?? false)) {
                continue;
            }

            $root = $this->normalise($disk['root'] ?? '');

            if ($root !== '' && str_starts_with($vaultRoot, $root)) {
                $offenders[] = $name;
            }
        }

        // Collected rather than asserted inside the loop, so this test says
        // something when no disk is served at all — which is the state it
        // is meant to protect, and the state in which a loop-only
        // assertion quietly proves nothing.
        $this->assertSame(
            [],
            $offenders,
            'these disks are served by the web server and their roots contain '
            .'the document vault: '.implode(', ', $offenders).'. Every '
            .'customer file under them is one config change away from being '
            .'public.',
        );
    }

    public function test_no_route_serves_a_filesystem_disk(): void
    {
        $paths = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'storage/'))
            ->values();

        // FynnEdge serves files through one authenticated,
        // ownership-checked endpoint and nowhere else.
        $this->assertSame(
            [],
            $paths->all(),
            'a route serves files straight off a disk',
        );
    }

    /**
     * Routes that serve a download and are not a customer's vault document.
     *
     * Each carries the reason it is allowed to exist, because the value of
     * this test is that a new way to get bytes out of FynnEdge has to be
     * argued for rather than merely added.
     *
     * Both entries below are registered unconditionally by filament/actions
     * as soon as the package is installed — the panel declares no export or
     * import action, and the `exports` and `imports` tables this deployment
     * would need are not migrated, so neither route can resolve a record.
     * If they ever could, they serve Filament's own export files rather
     * than the vault: a different model, a different disk, and the
     * controller aborts 401 for a guest and 403 unless the export belongs
     * to the administrator asking for it.
     *
     * @var array<string, string>
     */
    private const NOT_A_CUSTOMER_DOCUMENT = [
        'filament/exports/{export}/download' => 'Filament export files, owner-checked, '
            .'and inert here: no export action is declared and the table is not migrated.',

        'filament/imports/{import}/failed-rows/download' => 'The failed rows of a '
            .'Filament import, same guard and same reason.',
    ];

    public function test_the_only_way_to_a_document_is_the_owner_endpoint(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'download'))
            ->map(fn ($route) => $route->uri())
            ->reject(fn (string $uri) => array_key_exists($uri, self::NOT_A_CUSTOMER_DOCUMENT))
            ->values();

        $this->assertSame(
            ['api/v1/documents/{document}/download'],
            $routes->all(),
            'a route serves downloads that is neither the customer vault endpoint '
            .'nor listed with a reason in NOT_A_CUSTOMER_DOCUMENT',
        );
    }

    /**
     * The exempted routes are exempt because they never hand a guest bytes,
     * so that is what this checks. A future Filament release that dropped
     * its ownership test would leave the exemption above quietly untrue.
     *
     * Measured, these currently answer 500 rather than 401: route-model
     * binding looks for the `exports` row before the controller's auth
     * check runs, and the table is not migrated in this deployment. Untidy,
     * and not a disclosure — the request ends before anything is read from
     * a disk. Enabling exports in a later phase means migrating those
     * tables, at which point the answer becomes the 401 the controller
     * intends, and this assertion still holds.
     */
    public function test_the_filament_download_routes_never_serve_a_guest(): void
    {
        foreach (array_keys(self::NOT_A_CUSTOMER_DOCUMENT) as $uri) {
            $probe = str_replace(['{export}', '{import}'], '1', $uri);

            $status = $this->get('/'.$probe)->getStatusCode();

            $this->assertGreaterThanOrEqual(
                400,
                $status,
                "{$uri} answered an unauthenticated caller with {$status}",
            );
        }
    }

    private function normalise(?string $path): string
    {
        return rtrim((string) $path, '/').'/';
    }
}
