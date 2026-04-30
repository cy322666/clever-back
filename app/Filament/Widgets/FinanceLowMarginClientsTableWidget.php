<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;

class FinanceLowMarginClientsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Низкая маржинальность';

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
        $rows = app(FinanceAnalyticsService::class)->build($period)['low_margin_clients'] ?? collect();

        return $rows->map(fn ($row) => is_array($row) ? $row : [
            'id' => $row->id ?? null,
            'name' => $row->name ?? '—',
            'margin_target' => $row->margin_target ?? 0,
        ])->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')->label('Клиент')->wrap(),
            TextColumn::make('margin_target')->label('Margin target')->formatStateUsing(fn ($state) => number_format(((float) $state) * 100, 1, ',', ' ') . '%'),
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
