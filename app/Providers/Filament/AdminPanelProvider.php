<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The FynnEdge staff panel, at /admin.
 *
 * It authenticates against the `admin` guard and therefore against
 * `admin_users`. That single line is what keeps the two populations apart:
 * a customer's Sanctum token names a `User`, this guard's provider only
 * builds `AdminUser`, and there is consequently nothing here for a customer
 * token to be. The separation is structural rather than a check somebody
 * has to remember to write.
 *
 * The customer API is untouched by any of this. It is stateless, carries no
 * cookies and asks no session guard anything, so the session middleware
 * below applies to /admin routes only.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('FynnEdge')

            // The guard, and the whole point of this file.
            ->authGuard('admin')

            ->login()

            /*
             * No password reset, deliberately.
             *
             * It emails a link that signs somebody in, and this deployment's
             * mailer writes to a log file. Enabling it would put a working
             * account-recovery route in front of a channel that delivers
             * nothing, which is worse than not offering it: a locked-out
             * administrator would wait for an email instead of asking a
             * Super Admin to reset them. Turn it on with the mailer.
             */

            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')

            /*
             * The navigation FynnEdge actually has.
             *
             * Framework tables — cache, jobs, sessions, migrations, personal
             * access tokens — are absent and stay absent. They are not
             * business records, and a panel that lists them invites somebody
             * to edit one.
             */
            ->navigationGroups([
                NavigationGroup::make('Customers'),
                NavigationGroup::make('Loans'),
                NavigationGroup::make('KYC & Documents'),
                NavigationGroup::make('Products'),
                NavigationGroup::make('Support'),
                NavigationGroup::make('Communication'),
                NavigationGroup::make('Intelligence'),
                NavigationGroup::make('System'),
                NavigationGroup::make('Administration'),
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
