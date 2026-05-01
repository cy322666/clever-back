<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\EffectiveRateAnalyticsService;
use App\Support\AnalyticsPeriod;

class EffectiveRateStatsOverviewWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Фактическая ставка и маржа';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function statsData(): array
    {
        return app(EffectiveRateAnalyticsService::class)->build($this->resolvePeriod())['kpis'] ?? [];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
