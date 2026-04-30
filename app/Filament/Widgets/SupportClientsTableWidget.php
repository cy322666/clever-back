<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\SupportAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;

class SupportClientsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Сумма сделок в воронке сопровождения';

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
        $rows = app(SupportAnalyticsService::class)->build($period)['top_clients'] ?? collect();

        return $rows->map(fn ($row) => is_array($row) ? $row : [
            'id' => $row->id ?? null,
            'label' => $row->label ?? $row->name ?? '—',
            'deals_count' => $row->deals_count ?? 0,
            'won_amount' => $row->won_amount ?? 0,
            'total_amount' => $row->total_amount ?? 0,
            'last_activity_at' => $row->last_activity_at ?? null,
        ])->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('label')->label('Компания')->wrap(),
            TextColumn::make('deals_count')->label('Сделки')->sortable(),
            TextColumn::make('won_amount')->label('Won')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
            TextColumn::make('total_amount')->label('Сумма')->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')->sortable(),
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
