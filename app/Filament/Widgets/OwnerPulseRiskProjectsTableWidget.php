<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerPulseAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerPulseRiskProjectsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Проекты в зоне риска';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return app(OwnerPulseAnalyticsService::class)->build($this->resolvePeriod())['risk_projects'] ?? [];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultSort('progress_pct', 'desc')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Нет активных проектов с выработкой 80%+.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('project')->label('Проект')->wrap()->searchable()->sortable(),
            TextColumn::make('client')->label('Клиент')->wrap()->searchable()->sortable(),
            TextColumn::make('project_type')->label('Тип')->badge()->color('gray')->sortable(),
            TextColumn::make('responsible')->label('Ответственный')->wrap()->sortable(),
            TextColumn::make('planned_hours')->label('План часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))->sortable(),
            TextColumn::make('fact_hours')->label('Факт часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))->sortable(),
            TextColumn::make('progress_pct')
                ->label('% выработки')
                ->badge()
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ').'%')
                ->color(fn ($state): string => (float) $state >= 100 ? 'danger' : 'warning')
                ->sortable(),
            TextColumn::make('overrun_hours')->label('Перерасход')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ').' ч')->sortable(),
            TextColumn::make('missed_profit')->label('Упущенная прибыль')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('status')->label('Статус')->badge()->color(fn ($state): string => (string) $state === 'Сверх плана' ? 'danger' : 'warning')->sortable(),
            TextColumn::make('next_action')->label('Следующее действие')->wrap(),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
