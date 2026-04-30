<?php

namespace App\Filament\Widgets;

use App\Models\RevenueTransaction;
use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;

class FinanceTopClientsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Клиенты по выручке за период';

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
            'id' => md5((string) ($row->label ?? $row->name ?? '—')),
            'label' => $row->label ?? $row->name ?? '—',
            'value' => (float) ($row->value ?? 0),
            'net_value' => (float) ($row->net_value ?? $row->value ?? 0),
            'net_profit_percent' => $this->normalizeNetProfitPercent($row),
            'revenue_ids' => array_values(array_filter(explode(',', (string) ($row->revenue_ids ?? '')))),
        ])->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('label')->label('Клиент')->wrap(),
            TextColumn::make('value')->label('Выручка')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            SelectColumn::make('net_profit_percent')
                ->label('Доля чистыми')
                ->options([
                    '30' => '30%',
                    '50' => '50%',
                    '100' => '100%',
                ])
                ->placeholder('Разные')
                ->updateStateUsing(function ($state, array $record): ?string {
                    $this->updateNetProfitPercent($record['revenue_ids'] ?? [], (float) $state);

                    return is_numeric($state) ? (string) (int) $state : null;
                }),
            TextColumn::make('net_value')->label('Чистыми')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
        ];
    }

    protected function updateNetProfitPercent(array $revenueIds, float $percent): void
    {
        if ($revenueIds === [] || ! in_array((int) $percent, [30, 50, 100], true)) {
            return;
        }

        RevenueTransaction::query()
            ->whereIn('id', $revenueIds)
            ->update(['net_profit_percent' => $percent]);
    }

    protected function normalizeNetProfitPercent(object $row): ?string
    {
        $min = $row->min_net_profit_percent ?? null;
        $max = $row->max_net_profit_percent ?? null;

        if (! is_numeric($min) || ! is_numeric($max) || (float) $min !== (float) $max) {
            return null;
        }

        return (string) (int) round((float) $min);
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
