<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\EmployeeProductionChartWidget;
use App\Filament\Widgets\EmployeeProductionStatsWidget;
use App\Filament\Widgets\EmployeeHoursTableWidget;
use App\Filament\Widgets\EmployeeHoursChartWidget;
use App\Filament\Widgets\ProjectTypeHoursChartWidget;
use App\Filament\Widgets\ProductionProjectsTableWidget;
use App\Filament\Widgets\ProductionStatsOverviewWidget;
use App\Models\Employee;
use Filament\Support\Icons\Heroicon;

class Production extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;
    protected static ?string $navigationLabel = 'Производство';
    protected static ?int $navigationSort = 4;

    protected function widgets(): array
    {
        $employee = $this->selectedEmployeeUuid();

        return [
            $this->widget(ProductionStatsOverviewWidget::class, withPeriod: true),
            $this->widget(EmployeeProductionStatsWidget::class, ['employee' => $employee], true),
            $this->widget(EmployeeProductionChartWidget::class, ['employee' => $employee], true),
            $this->widget(EmployeeHoursChartWidget::class, withPeriod: true),
            $this->widget(ProjectTypeHoursChartWidget::class, withPeriod: true),
            $this->widget(EmployeeHoursTableWidget::class, withPeriod: true),
            $this->widget(ProductionProjectsTableWidget::class, withPeriod: true),
        ];
    }

    private function selectedEmployeeUuid(): ?string
    {
        $employee = (string) request()->query('employee', '');

        if (filled($employee)) {
            return $employee;
        }

        return Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->value('weeek_uuid');
    }

}
