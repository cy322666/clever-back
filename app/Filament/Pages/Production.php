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
use App\Support\AnalyticsPeriod;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
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

    protected function getHeaderActions(): array
    {
        $currentPeriod = AnalyticsPeriod::fromRequest(request());

        return [
            Action::make('period')
                ->label('Период')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->modalHeading('Фильтр периода')
                ->form([
                    Select::make('period')
                        ->label('Период')
                        ->required()
                        ->default($currentPeriod->key)
                        ->options([
                            'today' => 'Сегодня',
                            '7d' => '7 дней',
                            '30d' => '30 дней',
                            'month' => 'Текущий месяц',
                            'prev-month' => 'Прошлый месяц',
                            'quarter' => 'Квартал',
                            'all' => 'Всё время',
                            'custom' => 'Свой диапазон',
                        ]),
                    \Filament\Forms\Components\DatePicker::make('from')
                        ->label('С')
                        ->default($currentPeriod->from->toDateString())
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom'),
                    \Filament\Forms\Components\DatePicker::make('to')
                        ->label('По')
                        ->default($currentPeriod->to->toDateString())
                        ->visible(fn (Get $get): bool => $get('period') === 'custom')
                        ->required(fn (Get $get): bool => $get('period') === 'custom'),
                ])
                ->action(function (array $data): void {
                    $query = request()->query();
                    $query['period'] = $data['period'] ?? '30d';

                    if (($query['period'] ?? null) === 'custom') {
                        $query['from'] = $data['from'] ?? null;
                        $query['to'] = $data['to'] ?? null;
                    } else {
                        unset($query['from'], $query['to']);
                    }

                    $this->redirect(static::getUrl($query), navigate: true);
                }),
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
