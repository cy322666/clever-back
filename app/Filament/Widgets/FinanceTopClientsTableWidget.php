<?php

namespace App\Filament\Widgets;

use App\Models\RevenueTransaction;
use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FinanceTopClientsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Поступления по клиентам за период';

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
        $rows = app(FinanceAnalyticsService::class)->build($period)['revenue_transactions'] ?? collect();

        return $rows->map(fn ($row) => is_array($row) ? $row : [
            'id' => (int) $row->id,
            'counterparty' => $row->counterparty ?? 'Без клиента',
            'payment_label' => $row->payment_label ?? 'Поступление',
            'posted_at' => $row->posted_at,
            'value' => (float) ($row->value ?? 0),
            'net_value' => (float) ($row->net_value ?? 0),
            'net_profit_percent' => (string) (int) round((float) ($row->net_profit_percent ?? 100)),
            'counterparty_value' => (float) ($row->counterparty_value ?? 0),
            'counterparty_net_value' => (float) ($row->counterparty_net_value ?? 0),
            'counterparty_transactions_count' => (int) ($row->counterparty_transactions_count ?? 1),
        ])->all();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->groups([
                Group::make('counterparty')
                    ->label('Клиент')
                    ->collapsible()
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (array $record): string => (string) $record['counterparty'])
                    ->getDescriptionFromRecordUsing(function (array $record): string {
                        return 'Поступлений: '
                            . number_format((int) ($record['counterparty_transactions_count'] ?? 1), 0, ',', ' ')
                            . ' · Выручка: '
                            . number_format((float) $record['counterparty_value'], 0, ',', ' ')
                            . ' ₽ · Чистыми: '
                            . number_format((float) $record['counterparty_net_value'], 0, ',', ' ')
                            . ' ₽';
                    }),
            ])
            ->defaultGroup('counterparty')
            ->groupingSettingsHidden()
            ->paginated(false);
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('posted_at')
                ->label('Дата')
                ->dateTime('d.m.Y')
                ->sortable(),
            TextColumn::make('payment_label')
                ->label('Поступление')
                ->wrap()
                ->limit(90),
            TextColumn::make('value')->label('Выручка')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            SelectColumn::make('net_profit_percent')
                ->label('Доля прибыли')
                ->options([
                    '30' => '30%',
                    '50' => '50%',
                    '100' => '100%',
                ])
                ->selectablePlaceholder(false)
                ->updateStateUsing(function ($state, array $record): string {
                    $this->updateNetProfitPercent((int) $record['id'], (float) $state);

                    return is_numeric($state) ? (string) (int) $state : '100';
                }),
            TextColumn::make('net_value')->label('Прибыль')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
        ];
    }

    protected function updateNetProfitPercent(int $revenueId, float $percent): void
    {
        if ($revenueId <= 0 || ! in_array((int) $percent, [30, 50, 100], true)) {
            return;
        }

        RevenueTransaction::query()
            ->whereKey($revenueId)
            ->update(['net_profit_percent' => $percent]);
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
