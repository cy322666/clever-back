<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\FinanceAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FinanceExpenseByTypeTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Расходы по типам';

    protected int|string|array $columnSpan = 1;

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function rows(): array
    {
        return collect(app(FinanceAnalyticsService::class)->build($this->resolvePeriod())['expense_by_type'] ?? [])
            ->values()
            ->all();
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->defaultSort('amount', 'desc')
            ->emptyStateHeading('Нет данных за выбранный период')
            ->emptyStateDescription('Расходы появятся после импорта или ручного добавления транзакций.');
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('type')->label('Тип транзакции')->wrap()->sortable(),
            TextColumn::make('transactions_count')->label('Количество транзакций')->numeric()->sortable(),
            TextColumn::make('amount')->label('Сумма')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
            TextColumn::make('share')->label('Доля от расходов')->formatStateUsing(fn ($state) => $state === null ? 'Нет данных' : number_format((float) $state, 1, ',', ' ').'%')->sortable(),
            TextColumn::make('average_payment')->label('Средний платёж')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ').' ₽')->sortable(),
        ];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
