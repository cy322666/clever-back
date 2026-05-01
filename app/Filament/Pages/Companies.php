<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CompaniesStatsOverviewWidget;
use App\Filament\Widgets\CompaniesTableWidget;
use Filament\Support\Icons\Heroicon;

class Companies extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;
    protected static ?string $navigationLabel = 'Компании';
    protected static ?string $title = 'Компании';
    protected static ?int $navigationSort = 5;

    protected function widgets(): array
    {
        return [
            $this->widget(CompaniesStatsOverviewWidget::class, withPeriod: true),
            $this->widget(CompaniesTableWidget::class, withPeriod: true),
        ];
    }
}
