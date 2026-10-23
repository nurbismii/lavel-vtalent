<?php

namespace App\Providers\Filament;

use App\Filament\Pages\EditProfile;
use App\Http\Middleware\EnsurePortalAccess;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->login()
            ->brandName('VDNI · Talent Portal')
            ->brandLogo(asset('images/vdni-logo.png'))
            ->brandLogoHeight('2.5rem')
            ->profile(EditProfile::class)
            ->darkMode(false)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->multiFactorAuthentication([AppAuthentication::make()], isRequired: true)
            ->colors([
                'primary' => Color::generateV3Palette('#415e9e'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->homeUrl(fn () => route('filament.admin.pages.recruitment'))
            ->navigationGroups([
                'Persiapan rekrutmen',
                'Proses seleksi',
                'Komunikasi kandidat',
                'Administrasi sistem',
            ])
            ->navigationItems(array_map(
                fn (string $key, string $label, string $icon, ?string $group, int $sort): NavigationItem => NavigationItem::make($label)
                    ->icon($icon)
                    ->group($group)
                    ->sort($sort)
                    ->url(fn () => route('filament.admin.pages.recruitment', ['section' => $key]))
                    ->isActiveWhen(fn () => request()->routeIs('filament.admin.pages.recruitment') && request()->query('section', 'dashboard') === $key),
                ['dashboard', 'positions', 'periods', 'applications', 'tools', 'audit', 'operations', 'settings'],
                ['Ringkasan', 'Posisi', 'Periode rekrutmen', 'Kandidat & lamaran', 'Akses & tenggat kandidat', 'Riwayat aktivitas', 'Operasional', 'Pengaturan'],
                ['heroicon-o-squares-2x2', 'heroicon-o-briefcase', 'heroicon-o-calendar-days', 'heroicon-o-users', 'heroicon-o-adjustments-horizontal', 'heroicon-o-clock', 'heroicon-o-server', 'heroicon-o-cog-6-tooth'],
                [null, 'Persiapan rekrutmen', 'Persiapan rekrutmen', 'Proses seleksi', 'Proses seleksi', 'Administrasi sistem', 'Administrasi sistem', 'Administrasi sistem'],
                [0, 10, 20, 10, 20, 10, 20, 30],
            ))
            ->pages([])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
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
                EnsurePortalAccess::class,
            ], isPersistent: true);
    }
}
