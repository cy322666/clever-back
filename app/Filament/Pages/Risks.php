<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\RiskAlertsTableWidget;
use App\Filament\Widgets\ProjectLimitProjectsTableWidget;
use App\Filament\Widgets\ProjectLimitStatsOverviewWidget;
use App\Filament\Widgets\RiskStatsOverviewWidget;
use Filament\Support\Icons\Heroicon;

class Risks extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;
    protected static ?string $navigationLabel = 'Риски';
    protected static ?int $navigationSort = 7;

    protected function widgets(): array
    {
        return [
            $this->widget(RiskStatsOverviewWidget::class, withPeriod: true),
            $this->widget(ProjectLimitStatsOverviewWidget::class),
            $this->widget(ProjectLimitProjectsTableWidget::class),
            $this->widget(RiskAlertsTableWidget::class, withPeriod: true),
        ];
    }
}
