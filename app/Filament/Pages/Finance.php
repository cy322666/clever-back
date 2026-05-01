<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EffectiveRatesTableWidget;
use App\Filament\Widgets\EffectiveRateStatsOverviewWidget;
use App\Filament\Widgets\FinanceExpenseByTypeTableWidget;
use App\Filament\Widgets\FinanceIncomeByTypeTableWidget;
use App\Filament\Widgets\FinanceStatsOverviewWidget;
use App\Filament\Widgets\FinanceTopClientsTableWidget;
use App\Filament\Widgets\FinanceTransactionsTableWidget;
use App\Filament\Widgets\FinanceUnclassifiedTransactionsTableWidget;
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
            $this->widget(FinanceIncomeByTypeTableWidget::class, withPeriod: true),
            $this->widget(FinanceExpenseByTypeTableWidget::class, withPeriod: true),
            $this->widget(FinanceUnclassifiedTransactionsTableWidget::class, withPeriod: true),
            $this->widget(FinanceTransactionsTableWidget::class, withPeriod: true),
            $this->widget(EffectiveRateStatsOverviewWidget::class, withPeriod: true),
            $this->widget(EffectiveRatesTableWidget::class, withPeriod: true),
            $this->widget(FinanceTopClientsTableWidget::class, withPeriod: true),
        ];
    }
}
