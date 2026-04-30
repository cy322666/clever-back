<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EntityProductsTableWidget;
use App\Filament\Widgets\ProductsStatsOverviewWidget;
use Filament\Support\Icons\Heroicon;

class Products extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;
    protected static ?string $navigationLabel = 'Товары';
    protected static ?int $navigationSort = 2;

    protected function widgets(): array
    {
        return [
            $this->widget(ProductsStatsOverviewWidget::class, withPeriod: true),
            $this->widget(EntityProductsTableWidget::class, withPeriod: true),
        ];
    }
}
