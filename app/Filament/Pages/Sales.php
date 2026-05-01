<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\SalesSourcesChartWidget;
use App\Filament\Widgets\SalesPrimarySourcesChartWidget;
use App\Filament\Widgets\SalesSourcesStatsOverviewWidget;
use App\Filament\Widgets\SalesSourcesTableWidget;
use App\Filament\Widgets\SalesStatsOverviewWidget;
use Filament\Support\Icons\Heroicon;

class Sales extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;
    protected static ?string $navigationLabel = 'Продажи';
    protected static ?string $title = 'Продажи';
    protected static ?int $navigationSort = 1;

    protected function widgets(): array
    {
        return [
            $this->widget(SalesStatsOverviewWidget::class, withPeriod: true),
            $this->widget(SalesSourcesStatsOverviewWidget::class, withPeriod: true),
            $this->widget(SalesSourcesTableWidget::class, withPeriod: true),
            $this->widget(SalesSourcesChartWidget::class, withPeriod: true),
            $this->widget(SalesPrimarySourcesChartWidget::class, withPeriod: true),
        ];
    }
}
