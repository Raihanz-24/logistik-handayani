<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Widgets\AccountWidget;
use Awcodes\LightSwitch\Enums\Alignment;
use Awcodes\LightSwitch\LightSwitchPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Devonab\FilamentEasyFooter\EasyFooterPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Platform;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.ga4')->render()
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.dashboard-theme')->render()
            )
             // ->renderHook('head.end', fn () => view('filament.ga4'))
            ->sidebarCollapsibleOnDesktop()
            ->registration()
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name'))
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('3.45rem')
            ->favicon(asset('images/logo-handayani.webp'))
            ->login(Login::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                // Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                AccountWidget::class,
            ])

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->navigationItems([
                NavigationItem::make('Ubah Profil')
                    ->group('Pengaturan')
                    ->isActiveWhen(fn () => request()->routeIs('filament.admin.auth.profile'))
                    ->url(fn () => route('filament.admin.auth.profile', absolute: true)),
            ])
            ->navigationGroups([
                'Master Data',
                'Pengaturan',
                'Bantuan',
            ])
            ->spa()
            ->profile(isSimple: false)
            ->unsavedChangesAlerts()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchDebounce('750ms')
            ->globalSearchFieldSuffix(fn (): ?string => match (Platform::detect()) {
                Platform::Windows, Platform::Linux => 'CTRL+K',
                Platform::Mac => '⌘K',
                default => null,
            })
            ->plugins([
                FilamentShieldPlugin::make(),
                LightSwitchPlugin::make()
                    ->position(Alignment::TopCenter)
                    ->enabledOn([
                        'auth.email',
                        'auth.login',
                        'auth.password',
                        'auth.register',
                    ]),
                EasyFooterPlugin::make()
                    ->footerEnabled()
                    ->withFooterPosition('footer')
                    ->withLoadTime('Halaman ini dimuat'),
            ]);
    }
}
