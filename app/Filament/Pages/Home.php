<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OwnerPulseAttentionTableWidget;
use App\Filament\Widgets\OwnerPulseMoneyStatsWidget;
use App\Filament\Widgets\OwnerPulseOwnerHoursWidget;
use App\Filament\Widgets\OwnerPulseProductionStatsWidget;
use App\Filament\Widgets\OwnerPulseRiskProjectsTableWidget;
use App\Filament\Widgets\OwnerPulseSalesSourcesTableWidget;
use App\Filament\Widgets\OwnerPulseSalesStatsWidget;
use App\Filament\Widgets\OwnerPulseTeamLoadTableWidget;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class Home extends AnalyticsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;
    protected static ?string $navigationLabel = 'Главная';
    protected static ?string $title = 'Пульт собственника';
    protected static ?string $slug = 'home';
    protected static ?int $navigationSort = 0;

    protected function widgets(): array
    {
        return [
            $this->widget(OwnerPulseMoneyStatsWidget::class, withPeriod: true),
            $this->widget(OwnerPulseSalesStatsWidget::class, withPeriod: true),
            $this->widget(OwnerPulseProductionStatsWidget::class, withPeriod: true),
            $this->widget(OwnerPulseOwnerHoursWidget::class, withPeriod: true),
            $this->widget(OwnerPulseAttentionTableWidget::class, withPeriod: true),
            $this->widget(OwnerPulseSalesSourcesTableWidget::class, withPeriod: true),
            $this->widget(OwnerPulseRiskProjectsTableWidget::class, withPeriod: true),
            $this->widget(OwnerPulseTeamLoadTableWidget::class, withPeriod: true),
        ];
    }
}
