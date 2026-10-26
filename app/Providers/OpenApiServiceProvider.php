<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;

/**
 * What the generated OpenAPI document says beyond what the code already says.
 *
 * Scramble reads the routes, form requests and API resources and produces
 * the operations on its own. This adds the three things it cannot infer:
 * the server the paths hang off, the authentication scheme, and which
 * operations actually require it.
 *
 * Nothing here touches the API. It reads the route table and describes it.
 */
class OpenApiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $document): void {
                /*
                 * `/api/v1` is part of the contract, not a deployment
                 * detail: the Flutter client has it compiled in, and a
                 * document that omitted it would document a different API.
                 */
                $document->servers = [
                    Server::make(rtrim((string) config('app.url'), '/').'/api/v1')
                        ->setDescription('This deployment'),
                ];

                /*
                 * Declared once and referenced by operation, rather than
                 * applied to everything: three endpoints are genuinely
                 * public, and a document that demanded a token for
                 * `send-otp` would describe an API nobody could start using.
                 */
                $document->secure(
                    SecurityScheme::http('bearer')
                        ->as('sanctumBearer')
                        ->setDescription(
                            'A Sanctum token from `POST /auth/verify-otp`, sent as '
                            .'`Authorization: Bearer <token>`. Tokens last 30 days '
                            .'and are revoked per device by `POST /auth/logout`.'
                        )
                );
            })
            ->withOperationTransformers(function (Operation $operation, RouteInfo $routeInfo): void {
                $this->groupUnderOneTag($operation);

                if ($this->requiresAToken($routeInfo->route)) {
                    return;
                }

                /*
                 * An empty security requirement is how OpenAPI says "this
                 * one needs nothing", overriding the document-level default.
                 * Taken from the route's own middleware rather than a list
                 * kept here, so an endpoint that changes its authentication
                 * changes its documentation with it.
                 */
                $operation->addSecurity([]);
            });
    }

    /**
     * The controller each operation came from, mapped to what a reader is
     * actually looking for.
     *
     * Left alone, the tag is the controller's own name, which gives
     * twenty-six groups — `NotificationDevice` and `NotificationPreference`
     * sitting apart from `Notification`, `AiMemory` apart from `Ai`. That
     * is how the code is organised, not how somebody integrating against it
     * thinks, and a sidebar with twenty-six entries is a list to scroll
     * rather than a structure to navigate.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        'Auth' => 'Authentication',

        'Profile' => 'Customer',
        'Home' => 'Customer',
        'Consent' => 'Customer',

        'FinancialSummary' => 'Financial',
        'Goal' => 'Financial',
        'Score' => 'Financial',
        'FynnTwin' => 'Financial',
        'Intelligence' => 'Financial',
        'Decision' => 'Financial',

        'Product' => 'Products & Matching',
        'Match' => 'Products & Matching',

        'Application' => 'Applications',
        'ApplicationDocument' => 'Applications',
        'ApplicationSubmission' => 'Applications',

        'Document' => 'Documents & Verification',
        'DocumentScan' => 'Documents & Verification',
        'Kyc' => 'Documents & Verification',
        'Credit' => 'Documents & Verification',

        'Notification' => 'Notifications',
        'NotificationDevice' => 'Notifications',
        'NotificationPreference' => 'Notifications',

        'Support' => 'Support',

        'Ai' => 'FynnAI',
        'AiMemory' => 'FynnAI',

        'ProviderEvent' => 'Provider Ingress',
    ];

    /** Replaces the controller-derived tag with the group it belongs to. */
    private function groupUnderOneTag(Operation $operation): void
    {
        $operation->tags = array_values(array_unique(array_map(
            fn (string $tag): string => self::TAGS[$tag] ?? $tag,
            $operation->tags,
        )));
    }

    /** Whether this route is behind `auth:sanctum`. */
    private function requiresAToken(Route $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_contains($middleware, 'auth:sanctum')) {
                return true;
            }
        }

        return false;
    }
}
