<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use App\Support\FinanceTransactionTypes;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FinanceUnclassifiedTransactionsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Неклассифицированные транзакции';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return collect(app(FinanceAnalyticsService::class)->build($this->resolvePeriod())['unclassified_transactions'] ?? [])
            ->map(function (array $row): array {
                $row['transaction_type'] = '';

                return $row;
            })
            ->take(50)
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultSort('posted_at', 'desc')
            ->emptyStateHeading('Все транзакции классифицированы')
            ->emptyStateDescription('Новых операций без типа за выбранный период нет.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('posted_at')->label('Дата')->dateTime('d.m.Y')->sortable(),
            TextColumn::make('counterparty')->label('Контрагент')->wrap()->searchable()->sortable(),
            TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('direction_label')
                ->label('Направление')
                ->badge()
                ->color(fn (array $record): string => ($record['direction'] ?? null) === 'expense' ? 'warning' : 'success')
                ->sortable(),
            SelectColumn::make('transaction_type')
                ->label('Тип транзакции')
                ->options(function (array $record): array {
                    $types = ($record['direction'] ?? null) === 'expense'
                        ? FinanceTransactionTypes::EXPENSE
                        : FinanceTransactionTypes::INCOME;

                    return ['' => FinanceTransactionTypes::UNCLASSIFIED] + array_combine($types, $types);
                })
                ->selectablePlaceholder(false)
                ->updateStateUsing(function ($state, array $record): string {
                    app(FinanceAnalyticsService::class)->updateTransactionType(
                        (string) $record['source_table'],
                        (int) $record['source_id'],
                        (string) $state
                    );

                    return (string) $state;
                }),
            TextColumn::make('client')->label('Клиент')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('project')->label('Проект')->wrap()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('comment')->label('Комментарий')->wrap()->limit(120),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
