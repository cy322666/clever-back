<?php

namespace App\Services\Analytics;

use App\Models\MetricSnapshot;
use App\Services\CompanyResolver;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;

class MetricSnapshotService
{
    public function __construct(protected CompanyResolver $companyResolver) {}

    public function refresh(): int
    {
        $company = $this->companyResolver->resolve();
        $period = new AnalyticsPeriod(CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now()->endOfDay(), 'month');
        $saved = 0;

        $dashboard = app(OwnerDashboardService::class)->build($period);
        foreach ($dashboard['kpis'] as $item) {
            MetricSnapshot::query()->updateOrCreate([
                'snapshot_date' => $period->to->toDateString(),
                'period_key' => $period->key,
                'metric_group' => 'dashboard',
                'metric_key' => str($item['label'])->slug()->toString(),
            ], [
                'value_text' => $item['value'],
                'payload' => $item,
            ]);
            $saved++;
        }

        return $saved;
    }
}
