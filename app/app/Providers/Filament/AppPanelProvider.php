<?php

namespace App\Providers\Filament;

use App\Models\Brand;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->login()
            ->brandName('Media Ops')
            ->brandLogo(fn (): HtmlString|string|null => ($tenant = Filament::getTenant()) instanceof Brand
                && filled($tenant->logo_path)
                && Route::has('media.logo')
                ? new HtmlString('<img alt="'.e($tenant->name).'" src="'.e(route('media.logo', $tenant)).'" onerror="if(!this.dataset.r){this.dataset.r=1;this.src=this.src.split(\'?\')[0]+\'?r=\'+Date.now();}">')
                : null)
            ->tenant(Brand::class, slugAttribute: 'slug')
            ->tenantMenu(fn (): bool => auth()->user()->brands()->count() > 1)
            ->spa()
            ->renderHook(PanelsRenderHook::BODY_END, fn (): string => Filament::getTenant() instanceof Brand ? Blade::render('@livewire(\'piece-viewer\')') : '')
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn (): string => Filament::getTenant() instanceof Brand ? Blade::render('@livewire(\'jobs-bell\')') : '')
            ->colors([
                'primary' => Color::Red,
            ])
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
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
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
