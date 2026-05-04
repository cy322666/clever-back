<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\ProductionAnalyticsService;
use App\Support\AnalyticsPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class ProjectResponsibleChartWidget extends ChartWidget
{
    protected ?string $heading = 'Проекты по ответственным';

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
        $rows = collect(app(ProductionAnalyticsService::class)->build($period)['project_summary'] ?? [])
            ->filter(fn (array $row): bool => ($row['project_status'] ?? null) === 'active')
            ->groupBy(fn (array $row): string => filled($row['responsible_name'] ?? null) ? (string) $row['responsible_name'] : 'Не назначен')
            ->map(fn ($projects, string $responsible): array => [
                'responsible' => $responsible,
                'count' => $projects->count(),
            ])
            ->sortByDesc('count')
            ->values();

        return [
            'labels' => $rows->pluck('responsible')->all(),
            'datasets' => [
                [
                    'label' => 'Проекты',
                    'data' => $rows->pluck('count')->map(fn ($value) => (int) $value)->all(),
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
