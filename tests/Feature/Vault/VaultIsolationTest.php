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

    public function test_the_only_way_to_a_document_is_the_owner_endpoint(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'download'))
            ->map(fn ($route) => $route->uri())
            ->values();

        $this->assertSame(
            ['api/v1/documents/{document}/download'],
            $routes->all(),
        );
    }

    private function normalise(?string $path): string
    {
        return rtrim((string) $path, '/').'/';
    }
}
