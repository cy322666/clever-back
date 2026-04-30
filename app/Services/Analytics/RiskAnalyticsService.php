<?php

namespace App\Services\Analytics;

use App\Models\Alert;
use App\Support\AnalyticsPeriod;

class RiskAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $company = $this->company();

        $alerts = Alert::query()
            ->latest('detected_at')
            ->limit(50)
            ->get();

        $open = $alerts->where('status', 'open');
        $projectLimitWarning = $open->where('type', 'project_limit_warning')->count();
        $projectLimitExceeded = $open->where('type', 'project_limit_exceeded')->count();

        return [
            'kpis' => [
                ['label' => 'Открытые риски', 'value' => number_format($open->count()), 'hint' => 'Требуют реакции', 'tone' => 'rose'],
                ['label' => 'Критичные', 'value' => number_format($open->where('severity', 'critical')->count()), 'hint' => 'Немедленно', 'tone' => 'amber'],
                ['label' => 'Предупреждения', 'value' => number_format($open->where('severity', 'warning')->count()), 'hint' => 'Следить', 'tone' => 'brand'],
                ['label' => 'На грани', 'value' => number_format($projectLimitWarning), 'hint' => 'Проекты', 'tone' => 'amber'],
                ['label' => 'За лимитом', 'value' => number_format($projectLimitExceeded), 'hint' => 'Проекты', 'tone' => 'danger'],
            ],
            'by_severity' => [
                'labels' => ['critical', 'warning', 'info'],
                'values' => [
                    $open->where('severity', 'critical')->count(),
                    $open->where('severity', 'warning')->count(),
                    $open->where('severity', 'info')->count(),
                ],
            ],
            'alerts' => $alerts,
            'period' => $period,
        ];
    }
}
