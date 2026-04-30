<?php

namespace App\Filament\Pages;

use BackedEnum;
use App\Filament\Widgets\OwnerStatsOverviewWidget;
use App\Support\AnalyticsPeriod;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;
    protected static ?string $navigationLabel = 'Обзор';
    protected static ?int $navigationSort = 1;
    protected static ?string $title = 'Обзор';
    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        $this->redirect(Sales::getUrl(), navigate: true);
    }

    public function getWidgets(): array
    {
        $period = AnalyticsPeriod::fromRequest(request());

        return [
            OwnerStatsOverviewWidget::make(['period' => $period->toArray()]),
        ];
    }

    public function getColumns(): int | array
    {
        return 2;
    }
}
