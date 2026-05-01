<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerPulseAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerPulseTeamLoadTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Загрузка команды';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return app(OwnerPulseAnalyticsService::class)->build($this->resolvePeriod())['team_load'] ?? [];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultSort('utilization_pct', 'desc')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Нет сотрудников или записей учета времени за выбранный период.');
    }

    protected function getTableColumns(): array
    {
        return [
            IconColumn::make('is_owner')->label('')->boolean(),
            TextColumn::make('employee')->label('Сотрудник')->wrap()->searchable()->sortable(),
            TextColumn::make('role')->label('Роль')->badge()->color(fn (array $record): string => ($record['is_owner'] ?? false) ? 'warning' : 'gray')->sortable(),
            TextColumn::make('planned_hours')->label('План часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))->sortable(),
            TextColumn::make('fact_hours')->label('Факт часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))->sortable(),
            TextColumn::make('utilization_pct')
                ->label('Загрузка %')
                ->badge()
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ').'%')
                ->color(fn ($state): string => (float) $state >= 95 ? 'danger' : ((float) $state >= 85 ? 'warning' : ((float) $state > 0 ? 'success' : 'gray')))
                ->sortable(),
            TextColumn::make('active_projects')
                ->label('Активные проекты')
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
            TextColumn::make('overdue_tasks')->label('Просроченные задачи')->numeric()->sortable(),
            TextColumn::make('earned')->label('Заработано по ставке 3000 ₽/ч')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('owner_margin')->label('Маржа после ФОТ')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
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

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
