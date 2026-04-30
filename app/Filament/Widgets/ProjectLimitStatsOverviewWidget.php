<?php

namespace App\Filament\Widgets;

use App\Services\Alerts\ProjectLimitMonitorService;

class ProjectLimitStatsOverviewWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Проекты под контролем';

    protected int | string | array $columnSpan = 'full';

    protected function statsData(): array
    {
        return app(ProjectLimitMonitorService::class)->build()['kpis'] ?? [];
    }
}
