<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetAnalyticsContext;
use Filament\Auth\Pages\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Support\Enums\Width;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->maxContentWidth(Width::Full)
            ->login(Login::class)
            ->sidebarCollapsibleOnDesktop()
            ->collapsibleNavigationGroups(false)
            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\\Filament\\Pages',
            )
            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\\Filament\\Widgets',
            )
            ->middleware([
                'web',
                SetAnalyticsContext::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
