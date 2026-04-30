<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\InvoiceAnalyticsService;
use App\Support\AnalyticsPeriod;

class InvoicesStatsOverviewWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Счета';

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

        return app(InvoiceAnalyticsService::class)->build($period)['kpis'] ?? [];
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('all');
    }
}
