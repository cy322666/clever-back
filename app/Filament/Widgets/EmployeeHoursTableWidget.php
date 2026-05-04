<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerPulseAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EmployeeHoursTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Отработанные часы по сотрудникам';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->searchable(false)
            ->paginated(false);
    }

    protected function rows(): array
    {
        return app(OwnerPulseAnalyticsService::class)->build($this->resolvePeriod())['team_load'] ?? [];
    }

    protected function getTableHeaderActions(): array
    {
        return [
            $this->columnOrderAction('employee_hours', $this->baseTableColumns()),
            $this->resetColumnOrderAction('employee_hours'),
        ];
    }

    protected function getTableColumns(): array
    {
        return $this->applyColumnOrder('employee_hours', $this->baseTableColumns());
    }

    protected function baseTableColumns(): array
    {
        return [
            TextColumn::make('employee')
                ->label('Сотрудник')
                ->wrap(),
            TextColumn::make('fact_hours')
                ->label('Факт часов')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')
                ->sortable(),
            TextColumn::make('utilization_pct')
                ->label('Загрузка %')
                ->badge()
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . '%')
                ->color(fn ($state): string => (float) $state >= 95 ? 'danger' : ((float) $state >= 85 ? 'warning' : ((float) $state > 0 ? 'success' : 'gray')))
                ->sortable(),
            TextColumn::make('planned_hours')
                ->label('План часов')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')
                ->sortable(),
            TextColumn::make('active_projects')
                ->label('Активных проектов')
                ->numeric()
                ->tooltip(fn (array $record): ?string => filled($record['active_project_names'] ?? null) ? (string) $record['active_project_names'] : null)
                ->sortable(),
            TextColumn::make('responsible_projects_count')
                ->label('Ответственный за проектов')
                ->numeric()
                ->tooltip(fn (array $record): ?string => filled($record['responsible_projects'] ?? null) ? (string) $record['responsible_projects'] : null)
                ->sortable(),
            TextColumn::make('red_projects_count')
                ->label('Проекты в красной зоне')
                ->badge()
                ->color(fn ($state): string => (int) $state > 2 ? 'danger' : ((int) $state > 0 ? 'warning' : 'success'))
                ->sortable(),
            TextColumn::make('owner_margin')
                ->label('Маржа после ФОТ')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
            TextColumn::make('earned')
                ->label('Заработано по ставке 3000 ₽/ч')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
            TextColumn::make('status')
                ->label('Статус')
                ->badge()
                ->color(fn ($state): string => match ((string) $state) {
                    'Перегруз' => 'danger',
                    'Внимание' => 'warning',
                    'Норма' => 'success',
                    default => 'gray',
                })
                ->sortable(),
        ];
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
