<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\ProductAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;

class EntityProductsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Проданные товары';

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
        $rows = app(ProductAnalyticsService::class)->build($period)['rows'] ?? [];

        return collect($rows)
            ->filter(fn (array $row): bool => (float) ($row['total_amount'] ?? 0) > 0)
            ->map(function (array $row, int $index): array {
                $row['__key'] ??= (string) ($row['id'] ?? $index);

                return $row;
            })
            ->values()
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('product_name')->label('Товар')->wrap()->searchable(),
            TextColumn::make('category')
                ->label('Категория')
                ->wrap()
                ->sortable(),
            TextColumn::make('entities_count')
                ->label('Сущностей')
                ->sortable(),
            TextColumn::make('quantity')
                ->label('Кол-во')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' '))
                ->sortable(),
            TextColumn::make('unit_price')
                ->label('Цена')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
            TextColumn::make('total_amount')
                ->label('Сумма')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
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
