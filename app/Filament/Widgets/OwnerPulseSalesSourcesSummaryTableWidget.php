<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\SalesSourceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerPulseSalesSourcesSummaryTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Источники продаж: что даёт деньги';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return collect(app(SalesSourceAnalyticsService::class)->build($this->resolvePeriod())['rows'] ?? [])
            ->filter(fn (array $row): bool => (int) $row['new_leads'] > 0 || (int) $row['won_count'] > 0 || (float) $row['revenue'] > 0 || (float) $row['forecast'] > 0)
            ->take(8)
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultSort('revenue', 'desc')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Источники появятся после синхронизации лидов и сделок.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('source')->label('Источник')->wrap()->searchable()->sortable(),
            TextColumn::make('new_leads')->label('Лиды')->numeric()->sortable(),
            TextColumn::make('won_count')->label('Оплаты')->numeric()->sortable(),
            TextColumn::make('conversion')->label('Конверсия')->formatStateUsing(fn ($state) => $state === null ? 'Нет данных' : number_format((float) $state, 1, ',', ' ').'%')->sortable(),
            TextColumn::make('revenue')->label('Выручка')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('forecast')->label('Прогноз')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
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
