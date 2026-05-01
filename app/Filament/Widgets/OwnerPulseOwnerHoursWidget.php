<?php

namespace App\Filament\Widgets;

use App\Services\Analytics\OwnerPulseAnalyticsService;
use App\Support\AnalyticsPeriod;

class OwnerPulseOwnerHoursWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Мои часы в производстве';

    protected int|string|array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function statsData(): array
    {
        return app(OwnerPulseAnalyticsService::class)->build($this->resolvePeriod())['owner_hours_card'] ?? [];
    }

    protected function resolvePeriod(): AnalyticsPeriod
    {
        return $this->period !== [] ? AnalyticsPeriod::fromArray($this->period) : AnalyticsPeriod::preset('month');
    }
}
