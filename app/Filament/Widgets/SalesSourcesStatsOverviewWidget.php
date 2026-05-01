<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\SalesSourceAnalyticsService;
use App\Support\AnalyticsPeriod;

class SalesSourcesStatsOverviewWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Источники продаж';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function statsData(): array
    {
        return app(SalesSourceAnalyticsService::class)->build($this->resolvePeriod())['kpis'] ?? [];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
