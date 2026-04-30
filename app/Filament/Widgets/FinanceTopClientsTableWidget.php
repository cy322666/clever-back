<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;

class FinanceTopClientsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Топ клиентов по выручке';

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
        $rows = app(FinanceAnalyticsService::class)->build($period)['top_clients'] ?? collect();

        return $rows->map(fn ($row) => is_array($row) ? $row : [
            'id' => $row->id ?? null,
            'label' => $row->label ?? $row->name ?? '—',
            'value' => $row->value ?? 0,
        ])->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('label')->label('Клиент')->wrap(),
            TextColumn::make('value')->label('Выручка')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
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
