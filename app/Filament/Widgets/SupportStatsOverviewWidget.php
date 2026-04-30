<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\SupportAnalyticsService;
use App\Support\AnalyticsPeriod;

class SupportStatsOverviewWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Сопровождение';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function statsData(): array
    {
        $period = $this->resolvePeriod();

        return app(SupportAnalyticsService::class)->build($period)['kpis'] ?? [];
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
