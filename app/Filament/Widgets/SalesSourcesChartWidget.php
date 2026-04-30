<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\SalesAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class SalesSourcesChartWidget extends ChartWidget
{
    protected ?string $heading = 'Источники успешных сделок';

    protected int | string | array $columnSpan = 1;

    protected ?string $maxHeight = '280px';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $period = $this->resolvePeriod();
        $sources = app(SalesAnalyticsService::class)
            ->build($period)['charts']['sources_won'] ?? ['labels' => [], 'values' => []];

        return [
            'datasets' => [
                [
                    'label' => 'Won сделки',
                    'data' => $sources['values'] ?? [],
                    'backgroundColor' => 'rgba(16, 185, 129, 0.65)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $sources['labels'] ?? [],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return [
            'indexAxis' => 'y',
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                        'stepSize' => 1,
                    ],
                    'grid' => [
                        'color' => 'rgba(148, 163, 184, 0.18)',
                    ],
                ],
                'y' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
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
