<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerPulseAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnerPulseAttentionTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Требует внимания';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return app(OwnerPulseAnalyticsService::class)->build($this->resolvePeriod())['attention'] ?? [];
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Автоматических проблем и рисков не найдено.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('type')->label('Тип')->badge()->color('gray')->sortable(),
            TextColumn::make('object')->label('Объект')->wrap()->searchable()->sortable(),
            TextColumn::make('problem')->label('Проблема')->wrap()->sortable(),
            TextColumn::make('metric')->label('Показатель')->sortable(),
            TextColumn::make('action')->label('Рекомендуемое действие')->wrap(),
            TextColumn::make('responsible')->label('Ответственный')->wrap()->sortable(),
            TextColumn::make('priority')
                ->label('Приоритет')
                ->badge()
                ->color(fn (array $record): string => match ($record['priority_key'] ?? null) {
                    'high' => 'danger',
                    'medium' => 'warning',
                    default => 'gray',
                })
                ->sortable(),
            TextColumn::make('url')
                ->label('')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->formatStateUsing(fn () => '')
                ->url(fn (array $record): ?string => $record['url'] ?? null)
                ->openUrlInNewTab(false),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
