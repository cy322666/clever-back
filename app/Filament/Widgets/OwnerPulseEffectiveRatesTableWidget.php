<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EffectiveRateAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerPulseEffectiveRatesTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Фактическая ставка: зона риска';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return collect(app(EffectiveRateAnalyticsService::class)->build($this->resolvePeriod())['rows'] ?? [])
            ->filter(fn (array $row): bool => (bool) ($row['is_problem'] ?? false))
            ->take(8)
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Нет клиентов или проектов с низкой ставкой/перерасходом.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('client')->label('Клиент')->wrap()->searchable()->sortable(),
            TextColumn::make('project')->label('Проект')->wrap()->searchable()->sortable(),
            TextColumn::make('rate')
                ->label('Фактическая ставка')
                ->badge()
                ->formatStateUsing(fn ($state, array $record): string => (string) ($record['rate_label'] ?? 'нет данных'))
                ->color(fn (array $record): string => $record['tone'] ?? 'gray')
                ->sortable(),
            TextColumn::make('overrun_hours')->label('Перерасход')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ').' ч')->sortable(),
            TextColumn::make('missed_profit')->label('Упущенная прибыль')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('owner_margin')->label('Маржа')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('status')
                ->label('Статус')
                ->badge()
                ->color(fn (array $record): string => $record['tone'] ?? 'gray')
                ->sortable(),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
