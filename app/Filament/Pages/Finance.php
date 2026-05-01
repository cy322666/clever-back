<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FinanceStatsOverviewWidget;
use App\Filament\Widgets\FinanceTopClientsTableWidget;
use Filament\Support\Icons\Heroicon;

class Finance extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;
    protected static ?string $navigationLabel = 'Финансы';
    protected static ?string $title = 'Финансы';
    protected static ?int $navigationSort = 3;

    protected function widgets(): array
    {
        return [
            $this->widget(FinanceStatsOverviewWidget::class, withPeriod: true),
            $this->widget(FinanceTopClientsTableWidget::class, withPeriod: true),
        ];
    }
}
