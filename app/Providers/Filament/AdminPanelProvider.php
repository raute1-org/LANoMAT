<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Modules\Preflight\Filament\Widgets\PreflightStatusWidget;
use App\Modules\Tournaments\Filament\Resources\Tournaments\Pages\ManageDisputes;
use App\Support\Brand\ShellParity;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Filament\Actions\Action;
use Filament\Enums\UserMenuPosition;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
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
        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(ShellParity::BRAND_NAME)
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight(ShellParity::BRAND_LOGO_HEIGHT)
            // Same shell as the participant app: sidebar navigation (Filament's
            // default), user menu pinned to the sidebar footer, collapsible on desktop.
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->sidebarCollapsibleOnDesktop()
            ->userMenuItems([
                Action::make('back-to-app')
                    ->label(ShellParity::FILAMENT_BACK_LABEL)
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->url('/'),
            ])
            ->renderHook(PanelsRenderHook::BODY_START, fn () => view('filament.shell.admin-stripe'))
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn () => view('filament.shell.back-to-app'))
            // Closure: resolved at render time, after AppServiceProvider::boot() has
            // forced https in production (asset() here would bake in http://).
            ->favicon(fn (): string => asset('favicon.svg'))
            // Signal-amber brand accent (docs/design.md "Signalpult"): the
            // light-mode primary `#a85a00` is passed as a single hex so
            // Filament generates the full shade palette from it (Filament
            // v5 docs, "Styling > Overview > Generating a color palette" —
            // verified via laravel-boost search-docs against filament/filament@5.x).
            // `Color::hex()` would return the same array; the plain-string
            // form is the documented shorthand and needs no extra import.
            ->colors([
                'primary' => '#a85a00',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources');

        $modules = app(Modules::class);

        // Filament breaks equal navigationSort ties by registration order, so keep the
        // pre-toggle order; any other enabled module with resources is appended.
        $legacyOrder = [
            Module::Events, Module::Seating, Module::Schedule, Module::Catering, Module::Teams, Module::Games,
            Module::Tournaments, Module::Voting, Module::Infoscreen, Module::GameServers, Module::Hosts,
            Module::CustomServers, Module::Files, Module::Voice, Module::Gallery, Module::News,
        ];
        $ordered = [...$legacyOrder, ...array_filter(Module::cases(), fn (Module $m) => ! in_array($m, $legacyOrder, true))];

        foreach ($ordered as $module) {
            if (! $modules->enabled($module)) {
                continue;
            }

            $dir = app_path("Modules/{$module->name}/Filament/Resources");

            if (is_dir($dir)) {
                $panel->discoverResources(in: $dir, for: "App\\Modules\\{$module->name}\\Filament\\Resources");
            }
        }

        return $panel
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                ManageDisputes::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
                PreflightStatusWidget::class,
            ])
            ->middleware([
                AddContentSecurityPolicy::class.':panel',
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
