<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\ClientAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

class CompaniesTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Список компаний';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        $period = $this->resolvePeriod();

        return app(ClientAnalyticsService::class)->build($period)['companies'] ?? [];
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Компания')->wrap(),
            TextColumn::make('deal_count')->label('Сделки')->sortable(),
            TextColumn::make('won_count')->label('Продажи')->sortable(),
            TextColumn::make('won_revenue')->label('Сумма продаж')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            TextColumn::make('win_rate')->label('Win rate')->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . '%'),
            TextColumn::make('segment')->label('Сегмент')->badge(),
            IconColumn::make('entity_url')
                ->label('')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('primary')
                ->tooltip('Открыть в amo')
                ->url(fn (?string $state) => $state)
                ->openUrlInNewTab(),
        ];
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('all');
    }
}
