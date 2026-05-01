<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use App\Support\FinanceTransactionTypes;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class FinanceTransactionsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Финансовые транзакции';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        $rows = collect(app(FinanceAnalyticsService::class)->build($this->resolvePeriod())['finance_transactions'] ?? []);

        return $this->applyFilters($rows, $this->tableFilters ?? [])
            ->map(function (array $row): array {
                $row['transaction_type'] = (string) ($row['transaction_type'] ?? '');

                return $row;
            })
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        $rows = collect(app(FinanceAnalyticsService::class)->build($this->resolvePeriod())['finance_transactions'] ?? []);

        return parent::table($table)
            ->filters([
                SelectFilter::make('direction')
                    ->label('Направление')
                    ->options([
                        'income' => 'Поступление',
                        'expense' => 'Расход',
                    ]),
                SelectFilter::make('transaction_type')
                    ->label('Тип транзакции')
                    ->options($this->transactionTypeFilterOptions()),
                SelectFilter::make('client')
                    ->label('Клиент')
                    ->options($this->optionsFromRows($rows, 'client', skip: ['Не привязан'])),
                SelectFilter::make('project')
                    ->label('Проект')
                    ->options($this->optionsFromRows($rows, 'project', skip: ['Не привязан'])),
                SelectFilter::make('counterparty')
                    ->label('Контрагент')
                    ->options($this->optionsFromRows($rows, 'counterparty')),
            ])
            ->defaultSort('posted_at', 'desc')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Финансовые операции появятся после импорта банка или ручного добавления.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('posted_at')->label('Дата')->dateTime('d.m.Y')->sortable(),
            TextColumn::make('counterparty')->label('Контрагент')->wrap()->searchable()->sortable(),
            TextColumn::make('client')->label('Клиент')->wrap()->searchable()->sortable(),
            TextColumn::make('project')->label('Проект')->wrap()->searchable()->sortable(),
            TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('direction_label')
                ->label('Направление')
                ->badge()
                ->color(fn (array $record): string => ($record['direction'] ?? null) === 'expense' ? 'warning' : 'success')
                ->sortable(),
            SelectColumn::make('transaction_type')
                ->label('Тип транзакции')
                ->options(fn (array $record): array => $this->transactionTypeOptionsForRecord($record))
                ->selectablePlaceholder(false)
                ->updateStateUsing(function ($state, array $record): string {
                    app(FinanceAnalyticsService::class)->updateTransactionType(
                        (string) $record['source_table'],
                        (int) $record['source_id'],
                        (string) $state
                    );

                    return (string) $state;
                }),
            TextColumn::make('comment')->label('Комментарий')->wrap()->limit(100),
            TextColumn::make('invoice_label')->label('Связанный счёт')->wrap()->sortable(),
        ];
    }

    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $direction = data_get($filters, 'direction.value');
        $type = data_get($filters, 'transaction_type.value');
        $client = data_get($filters, 'client.value');
        $project = data_get($filters, 'project.value');
        $counterparty = data_get($filters, 'counterparty.value');

        return $rows
            ->when(filled($direction), fn (Collection $rows) => $rows->where('direction', (string) $direction))
            ->when($type !== null && $type !== '_unclassified', fn (Collection $rows) => $rows->where('transaction_type', (string) $type))
            ->when($type === '_unclassified', fn (Collection $rows) => $rows->filter(fn (array $row): bool => blank($row['transaction_type'] ?? null)))
            ->when(filled($client), fn (Collection $rows) => $rows->where('client', (string) $client))
            ->when(filled($project), fn (Collection $rows) => $rows->where('project', (string) $project))
            ->when(filled($counterparty), fn (Collection $rows) => $rows->where('counterparty', (string) $counterparty));
    }

    private function transactionTypeFilterOptions(): array
    {
        $options = FinanceTransactionTypes::allOptions();
        unset($options['']);

        return ['_unclassified' => FinanceTransactionTypes::UNCLASSIFIED] + $options;
    }

    private function transactionTypeOptionsForRecord(array $record): array
    {
        $types = ($record['direction'] ?? null) === 'expense'
            ? FinanceTransactionTypes::EXPENSE
            : FinanceTransactionTypes::INCOME;

        return ['' => FinanceTransactionTypes::UNCLASSIFIED] + array_combine($types, $types);
    }

    /**
     * @param  array<int, string>  $skip
     */
    private function optionsFromRows(Collection $rows, string $key, array $skip = []): array
    {
        return $rows
            ->pluck($key, $key)
            ->filter(fn ($value): bool => filled($value) && ! in_array((string) $value, $skip, true))
            ->unique()
            ->sort()
            ->all();
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
