<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BuyersStatsOverviewWidget;
use App\Filament\Widgets\BuyersTableWidget;
use Filament\Support\Icons\Heroicon;

class Buyers extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Продления';

    protected static ?string $title = 'Продления';

    protected static ?int $navigationSort = 6;

    protected function widgets(): array
    {
        return [
            $this->widget(BuyersStatsOverviewWidget::class, withPeriod: true),
            $this->widget(BuyersTableWidget::class, withPeriod: true),
        ];
    }
}
