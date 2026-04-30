<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\ProductionAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class EmployeeHoursChartWidget extends ChartWidget
{
    protected ?string $heading = 'Часы по сотрудникам';

    protected int | string | array $columnSpan = 1;

    protected ?string $maxHeight = '320px';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getData(): array
    {
        $period = $this->resolvePeriod();
        $summary = app(ProductionAnalyticsService::class)->build($period)['employee_summary'] ?? collect();

        $rows = collect($summary)
            ->filter(fn (array $row): bool => (float) ($row['hours'] ?? 0) > 0)
            ->sortByDesc('hours')
            ->take(8)
            ->values();

        return [
            'labels' => $rows->map(fn (array $row): string => (string) data_get($row, 'employee.name', 'Сотрудник'))->all(),
            'datasets' => [
                [
                    'label' => 'Часы',
                    'data' => $rows->pluck('hours')->map(fn ($value) => (float) $value)->all(),
                    'backgroundColor' => [
                        'rgba(59, 130, 246, 0.75)',
                        'rgba(16, 185, 129, 0.75)',
                        'rgba(245, 158, 11, 0.75)',
                        'rgba(239, 68, 68, 0.75)',
                        'rgba(168, 85, 247, 0.75)',
                        'rgba(14, 165, 233, 0.75)',
                        'rgba(34, 197, 94, 0.75)',
                        'rgba(244, 114, 182, 0.75)',
                    ],
                    'borderColor' => 'rgba(15, 23, 42, 0.12)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return [
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
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
