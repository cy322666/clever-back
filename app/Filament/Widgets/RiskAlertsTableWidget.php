<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\RiskAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;

class RiskAlertsTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Открытые риски';

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
        $rows = app(RiskAnalyticsService::class)->build($period)['alerts'] ?? collect();

        return $rows->map(fn ($row) => is_array($row) ? $row : [
            'id' => $row->id ?? null,
            'title' => $row->title ?? '—',
            'type' => $row->type ?? '—',
            'severity' => $row->severity ?? 'info',
            'status' => $row->status ?? 'open',
            'detected_at' => $row->detected_at ?? null,
        ])->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('title')->label('Заголовок')->wrap(),
            TextColumn::make('type')->label('Тип')->badge(),
            TextColumn::make('severity')->label('Серьёзность')->badge(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('detected_at')->label('Обнаружен')->dateTime('d.m.Y H:i'),
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
