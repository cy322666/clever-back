<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\SalesSourceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SalesSourcesTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Источники продаж';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return app(SalesSourceAnalyticsService::class)
            ->build($this->resolvePeriod(), $this->tableFilters ?? [])['rows'] ?? [];
    }

    public function table(Table $table): Table
    {
        $options = app(SalesSourceAnalyticsService::class)->build($this->resolvePeriod())['filter_options'] ?? [];

        return parent::table($table)
            ->filters([
                SelectFilter::make('pipeline')
                    ->label('Воронка')
                    ->options($options['pipelines'] ?? []),
                SelectFilter::make('source')
                    ->label('Источник')
                    ->options($options['sources'] ?? []),
                TernaryFilter::make('has_leads')
                    ->label('Только источники с лидами')
                    ->trueLabel('С лидами')
                    ->falseLabel('Все'),
                TernaryFilter::make('has_sales')
                    ->label('Только источники с продажами')
                    ->trueLabel('С продажами')
                    ->falseLabel('Все'),
            ])
            ->defaultSort('revenue', 'desc')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Источники появятся после синхронизации лидов и сделок.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('source')->label('Источник')->wrap()->searchable()->sortable(),
            TextColumn::make('new_leads')->label('Новые лиды')->numeric()->sortable(),
            TextColumn::make('qualified_leads')->label('Квалифицированные лиды')->numeric()->sortable(),
            TextColumn::make('proposal_sent')->label('КП отправлено')->numeric()->sortable(),
            TextColumn::make('won_count')->label('Выигранные сделки')->numeric()->sortable(),
            TextColumn::make('lost_count')->label('Проигранные сделки')->numeric()->sortable(),
            TextColumn::make('conversion')->label('Конверсия лид → оплата')->formatStateUsing(fn ($state) => $state === null ? 'Нет данных' : number_format((float) $state, 1, ',', ' ').'%')->sortable(),
            TextColumn::make('revenue')->label('Выручка')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('average_check')->label('Средний чек')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('pipeline')->label('Открытый pipeline')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('forecast')->label('Прогноз с учетом вероятности')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('avg_cycle_days')->label('Средний цикл сделки')->formatStateUsing(fn ($state) => $state === null ? 'Нет данных' : number_format((float) $state, 1, ',', ' ').' дн.')->sortable(),
            TextColumn::make('loss_reasons')->label('Причины отказов')->wrap()->limit(80),
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
