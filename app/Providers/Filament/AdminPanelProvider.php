<?php

namespace App\Providers\Filament;

use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\ManageDisputes;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(
                in: app_path('Modules/Events/Filament/Resources'),
                for: 'App\Modules\Events\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Seating/Filament/Resources'),
                for: 'App\Modules\Seating\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Schedule/Filament/Resources'),
                for: 'App\Modules\Schedule\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Catering/Filament/Resources'),
                for: 'App\Modules\Catering\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Teams/Filament/Resources'),
                for: 'App\Modules\Teams\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Tournaments/Filament/Resources'),
                for: 'App\Modules\Tournaments\Filament\Resources',
            )
            ->discoverResources(
                in: app_path('Modules/Voting/Filament/Resources'),
                for: 'App\Modules\Voting\Filament\Resources',
            )
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                ManageDisputes::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
