<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EffectiveRateAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EffectiveRatesTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Фактическая ставка по клиентам';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return app(EffectiveRateAnalyticsService::class)
            ->build($this->resolvePeriod(), $this->tableFilters ?? [])['rows'] ?? [];
    }

    public function table(Table $table): Table
    {
        $options = app(EffectiveRateAnalyticsService::class)
            ->build($this->resolvePeriod())['filter_options'] ?? [];

        return parent::table($table)
            ->filters([
                SelectFilter::make('project_type')
                    ->label('Тип проекта')
                    ->options($options['project_types'] ?? []),
                SelectFilter::make('manager')
                    ->label('Ответственный')
                    ->options($options['managers'] ?? []),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options($options['statuses'] ?? []),
                TernaryFilter::make('problem_only')
                    ->label('Только проблемные')
                    ->trueLabel('Только проблемные')
                    ->falseLabel('Все'),
            ])
            ->defaultSort('status_sort')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Нет проектов или клиентов с выручкой/часами за выбранный период.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('client')
                ->label('Клиент')
                ->wrap()
                ->searchable()
                ->sortable()
                ->url(fn (array $record): ?string => $record['client_url'] ?? null)
                ->openUrlInNewTab(),
            TextColumn::make('project')
                ->label('Проект')
                ->wrap()
                ->searchable()
                ->sortable()
                ->url(fn (array $record): ?string => $record['project_url'] ?? null),
            TextColumn::make('project_type')->label('Тип проекта')->badge()->color('gray')->sortable(),
            TextColumn::make('manager')->label('Ответственный')->wrap()->sortable(),
            TextColumn::make('revenue')->label('Выручка за период')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('fact_hours')->label('Факт часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))->sortable(),
            TextColumn::make('planned_hours')->label('План часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))->sortable(),
            TextColumn::make('progress_pct')
                ->label('% выработки')
                ->formatStateUsing(fn ($state) => $state === null ? 'нет плана' : number_format((float) $state, 1, ',', ' ').'%')
                ->badge()
                ->color(fn ($state): string => $state === null ? 'gray' : ((float) $state >= 100 ? 'danger' : ((float) $state >= 80 ? 'warning' : 'success')))
                ->sortable(),
            TextColumn::make('rate')
                ->label('Фактическая ставка')
                ->badge()
                ->formatStateUsing(fn ($state, array $record): string => (string) ($record['rate_label'] ?? 'нет данных'))
                ->color(fn (array $record): string => $record['tone'] ?? 'gray')
                ->sortable(),
            TextColumn::make('overrun_hours')->label('Перерасход часов')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ').' ч')->sortable(),
            TextColumn::make('missed_profit')->label('Упущенная прибыль')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('owner_margin')->label('Маржа')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('status')
                ->label('Статус')
                ->badge()
                ->color(fn (array $record): string => $record['tone'] ?? 'gray')
                ->sortable(),
            TextColumn::make('project_url')
                ->label('')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->formatStateUsing(fn () => '')
                ->url(fn (array $record): ?string => $record['project_url'] ?? $record['client_url'] ?? null)
                ->openUrlInNewTab(false),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
