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
    protected static ?string $title = 'Производство';
    protected static ?int $navigationSort = 2;

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
            $isExcluded = Employee::query()
                ->where('weeek_uuid', $employee)
                ->get(['name'])
                ->contains(fn (Employee $employee): bool => $this->isExcludedProductionEmployee((string) $employee->name));

            if ($isExcluded) {
                return $this->firstVisibleEmployeeUuid();
            }

            return $employee;
        }

        return $this->firstVisibleEmployeeUuid();
    }

    private function firstVisibleEmployeeUuid(): ?string
    {
        return Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->get(['name', 'weeek_uuid'])
            ->reject(fn (Employee $employee): bool => $this->isExcludedProductionEmployee((string) $employee->name))
            ->value('weeek_uuid');
    }

    private function isExcludedProductionEmployee(string $name): bool
    {
        $normalizedName = mb_strtolower(trim($name));

        if ($normalizedName === '') {
            return false;
        }

        foreach ((array) config('dashboard.production_excluded_employee_names', []) as $excludedName) {
            $normalizedExcludedName = mb_strtolower(trim((string) $excludedName));

            if ($normalizedExcludedName !== '' && str_contains($normalizedName, $normalizedExcludedName)) {
                return true;
            }
        }

        return false;
    }

}
