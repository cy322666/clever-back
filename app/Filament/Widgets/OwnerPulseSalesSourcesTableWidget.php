<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerPulseAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerPulseSalesSourcesTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Источники продаж';

    protected int|string|array $columnSpan = 'full';

    protected ?string $defaultSortColumn = 'revenue';

    protected ?string $defaultSortDirection = 'desc';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return app(OwnerPulseAnalyticsService::class)->build($this->resolvePeriod())['sales_sources'] ?? [];
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
            TextColumn::make('leads')->label('Лиды')->numeric()->sortable(),
            TextColumn::make('won_count')->label('Выигранные сделки')->numeric()->sortable(),
            TextColumn::make('conversion')->label('Конверсия')->formatStateUsing(fn ($state) => $state === null ? 'Нет данных' : number_format((float) $state, 1, ',', ' ').'%')->sortable(),
            TextColumn::make('revenue')->label('Выручка')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('average_check')->label('Средний чек')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('pipeline')->label('Pipeline')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('forecast')->label('Прогноз')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('status')->label('Комментарий/статус')->badge()->color(fn ($state): string => match ((string) $state) {
                'Работает' => 'success',
                'Есть потенциал' => 'warning',
                default => 'gray',
            }),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
