<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\SalesOpportunity;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $previousPeriod = $period->previousComparable();
        $supportPipelineName = $this->supportPipelineName();
        $idleThresholdDays = (int) config('dashboard.thresholds.deal_idle_days', 4);

        $supportBase = $this->supportPipelineQuery($supportPipelineName);
        $supportDealCount = (clone $supportBase)->count();
        $wonPeriodCount = (clone $supportBase)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->count();
        $lostPeriodCount = (clone $supportBase)
            ->where('sales_opportunities.status', 'lost')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->count();
        $openAmount = (float) (clone $supportBase)
            ->where('sales_opportunities.status', 'open')
            ->sum('sales_opportunities.amount');
        $forecastAmount = (float) (clone $supportBase)
            ->where('sales_opportunities.status', 'open')
            ->sum(DB::raw('sales_opportunities.amount * sales_opportunities.probability / 100.0'));
        $wonRevenue = (float) (clone $supportBase)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->sum('sales_opportunities.amount');
        $previousWonPeriodCount = (clone $supportBase)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to])
            ->count();
        $previousLostPeriodCount = (clone $supportBase)
            ->where('sales_opportunities.status', 'lost')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to])
            ->count();
        $previousOpenAmount = (float) (clone $supportBase)
            ->where('sales_opportunities.status', 'open')
            ->sum('sales_opportunities.amount');
        $openedSeries = $this->dailySeries(
            $period,
            (clone $supportBase)
                ->selectRaw("date_trunc('day', coalesce(sales_opportunities.opened_at, sales_opportunities.created_at))::date as date, count(*) as total")
                ->whereBetween(DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
            'date',
            'total'
        );

        $wonSeries = $this->dailySeries(
            $period,
            (clone $supportBase)
                ->selectRaw("date_trunc('day', coalesce(sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at))::date as date, count(*) as total")
                ->where('sales_opportunities.status', 'won')
                ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
            'date',
            'total'
        );

        $lostSeries = $this->dailySeries(
            $period,
            (clone $supportBase)
                ->selectRaw("date_trunc('day', coalesce(sales_opportunities.closed_at, sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at))::date as date, count(*) as total")
                ->where('sales_opportunities.status', 'lost')
                ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
            'date',
            'total'
        );

        $activitySeries = $this->dailySeries(
            $period,
            (clone $supportBase)
                ->selectRaw("date_trunc('day', sales_opportunities.last_activity_at)::date as date, count(*) as total")
                ->whereBetween('sales_opportunities.last_activity_at', [$period->from, $period->to])
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
            'date',
            'total'
        );

        $companyIdSql = "coalesce(
            nullif(clients.external_id, '')::bigint,
            nullif(sales_opportunities.metadata #>> '{amo_lead,companies,0,id}', '')::bigint,
            nullif(sales_opportunities.metadata #>> '{amo_lead,company,id}', '')::bigint,
            0
        )";
        $companyLabelSql = "coalesce(
            nullif(company_clients.name, ''),
            nullif(clients.name, ''),
            nullif(sales_opportunities.metadata #>> '{amo_lead,companies,0,name}', ''),
            nullif(sales_opportunities.metadata #>> '{amo_lead,company,name}', ''),
            'Без клиента'
        )";

        $clientRows = (clone $supportBase)
            ->leftJoin('clients', 'clients.id', '=', 'sales_opportunities.client_id')
            ->leftJoin('clients as company_clients', function ($join) use ($companyIdSql) {
                $join->whereRaw("nullif(company_clients.external_id, '')::bigint = {$companyIdSql}");
            })
            ->selectRaw("
                {$companyIdSql} as company_id,
                {$companyLabelSql} as label,
                count(*) as deals_count,
                sum(case when sales_opportunities.status = 'open' then coalesce(sales_opportunities.amount, 0) else 0 end) as open_amount,
                sum(case when sales_opportunities.status = 'won' then coalesce(sales_opportunities.amount, 0) else 0 end) as won_amount,
                sum(coalesce(sales_opportunities.amount, 0)) as total_amount,
                max(coalesce(sales_opportunities.last_activity_at, sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)) as last_activity_at
            ")
            ->groupByRaw($companyIdSql.', '.$companyLabelSql)
            ->orderByDesc('total_amount')
            ->limit(8)
            ->get();

        return [
            'support_pipeline_name' => $supportPipelineName,
            'summary' => [
                'total_deals' => $supportDealCount,
                'won_period' => $wonPeriodCount,
                'lost_period' => $lostPeriodCount,
                'open_amount' => $openAmount,
                'forecast_amount' => $forecastAmount,
                'won_revenue' => $wonRevenue,
            ],
            'kpis' => [
                ['label' => 'Закрыто успешно', 'value' => number_format($wonPeriodCount), 'hint' => 'За период', 'tone' => 'emerald', 'comparison' => $this->compareValues($wonPeriodCount, $previousWonPeriodCount)],
                ['label' => 'Закрыто в минус', 'value' => number_format($lostPeriodCount), 'hint' => 'За период', 'tone' => 'rose', 'comparison' => $this->compareValues($lostPeriodCount, $previousLostPeriodCount)],
                ['label' => 'Сумма в работе', 'value' => number_format($openAmount, 0, ',', ' '), 'hint' => 'Open amount', 'tone' => 'cyan', 'comparison' => $this->compareValues($openAmount, $previousOpenAmount)],
            ],
            'charts' => [
                'movement_series' => [
                    'labels' => $openedSeries['labels'],
                    'datasets' => [
                        [
                            'label' => 'Открыто',
                            'data' => $openedSeries['values'],
                            'borderColor' => '#38bdf8',
                            'backgroundColor' => 'rgba(56, 189, 248, 0.18)',
                            'fill' => false,
                        ],
                        [
                            'label' => 'Успешно закрыто',
                            'data' => $wonSeries['values'],
                            'borderColor' => '#34d399',
                            'backgroundColor' => 'rgba(52, 211, 153, 0.18)',
                            'fill' => false,
                        ],
                        [
                            'label' => 'Закрыто в минус',
                            'data' => $lostSeries['values'],
                            'borderColor' => '#fb7185',
                            'backgroundColor' => 'rgba(251, 113, 133, 0.18)',
                            'fill' => false,
                        ],
                        [
                            'label' => 'Активность',
                            'data' => $activitySeries['values'],
                            'borderColor' => '#a78bfa',
                            'backgroundColor' => 'rgba(167, 139, 250, 0.18)',
                            'fill' => false,
                        ],
                    ],
                ],
                'top_clients' => [
                    'labels' => $clientRows->pluck('label')->values()->all(),
                    'values' => $clientRows->pluck('total_amount')->map(fn ($value) => (float) $value)->values()->all(),
                    'options' => [
                        'indexAxis' => 'y',
                    ],
                ],
            ],
            'top_clients' => $clientRows,
            'period' => $period,
        ];
    }

    protected function supportPipelineName(): string
    {
        return trim((string) config('dashboard.support_pipeline_name', 'Сопровождение'));
    }

    protected function supportPipelineQuery(string $supportPipelineName)
    {
        return SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($supportPipelineName)]);
    }

}
