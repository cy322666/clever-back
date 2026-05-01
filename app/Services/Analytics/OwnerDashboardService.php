<?php

namespace App\Services\Analytics;

use App\Models\CashflowEntry;
use App\Models\Employee;
use App\Models\ExpenseTransaction;
use App\Models\Project;
use App\Models\RevenueTransaction;
use App\Models\SalesOpportunity;
use App\Models\TaskTimeEntry;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OwnerDashboardService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $previousPeriod = $period->previousComparable();
        $threshold = config('dashboard.thresholds');
        $primaryPipelineName = $this->primaryPipelineName();
        $allowedPipelineNames = collect(config('dashboard.amo_allowed_pipeline_names', []))
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();
        $excludedPipelineNames = collect(config('dashboard.amo_excluded_pipeline_names', []))
            ->map(fn ($value): string => Str::lower(trim((string) $value)))
            ->filter()
            ->values()
            ->all();

        $periodCondition = DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)');
        $wonCondition = DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)');

        $mainPipeline = $this->pipelineQueryByName($this->primaryPipelineName(), $allowedPipelineNames, $excludedPipelineNames);
        $repeatPipeline = $this->pipelineQueryByName($this->repeatPipelineName(), $allowedPipelineNames, $excludedPipelineNames);
        $supportPipeline = $this->pipelineQueryByName($this->supportPipelineName(), $allowedPipelineNames, $excludedPipelineNames);

        $mainCreatedCount = (clone $mainPipeline)
            ->whereBetween($periodCondition, [$period->from, $period->to])
            ->count();
        $previousMainCreatedCount = (clone $mainPipeline)
            ->whereBetween($periodCondition, [$previousPeriod->from, $previousPeriod->to])
            ->count();

        $mainActiveQuery = (clone $mainPipeline)
            ->where('sales_opportunities.status', 'open')
            ->whereBetween($periodCondition, [$period->from, $period->to]);
        $previousMainActiveQuery = (clone $mainPipeline)
            ->where('sales_opportunities.status', 'open')
            ->whereBetween($periodCondition, [$previousPeriod->from, $previousPeriod->to]);

        $mainActiveCount = (clone $mainActiveQuery)->count();
        $mainActiveAmount = (float) (clone $mainActiveQuery)->sum('sales_opportunities.amount');
        $previousMainActiveCount = (clone $previousMainActiveQuery)->count();
        $previousMainActiveAmount = (float) (clone $previousMainActiveQuery)->sum('sales_opportunities.amount');

        $mainWonQuery = (clone $mainPipeline)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween($wonCondition, [$period->from, $period->to]);
        $previousMainWonQuery = (clone $mainPipeline)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween($wonCondition, [$previousPeriod->from, $previousPeriod->to]);

        $mainWonCount = (clone $mainWonQuery)->count();
        $mainWonAmount = (float) (clone $mainWonQuery)->sum('sales_opportunities.amount');
        $previousMainWonCount = (clone $previousMainWonQuery)->count();
        $previousMainWonAmount = (float) (clone $previousMainWonQuery)->sum('sales_opportunities.amount');

        $repeatWonQuery = (clone $repeatPipeline)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween($wonCondition, [$period->from, $period->to]);
        $previousRepeatWonQuery = (clone $repeatPipeline)
            ->where('sales_opportunities.status', 'won')
            ->whereBetween($wonCondition, [$previousPeriod->from, $previousPeriod->to]);

        $repeatWonCount = (clone $repeatWonQuery)->count();
        $repeatWonAmount = (float) (clone $repeatWonQuery)->sum('sales_opportunities.amount');
        $previousRepeatWonCount = (clone $previousRepeatWonQuery)->count();
        $previousRepeatWonAmount = (float) (clone $previousRepeatWonQuery)->sum('sales_opportunities.amount');

        $supportActiveQuery = (clone $supportPipeline)
            ->where('sales_opportunities.status', 'open')
            ->whereBetween($periodCondition, [$period->from, $period->to]);
        $previousSupportActiveQuery = (clone $supportPipeline)
            ->where('sales_opportunities.status', 'open')
            ->whereBetween($periodCondition, [$previousPeriod->from, $previousPeriod->to]);

        $supportActiveCount = (clone $supportActiveQuery)->count();
        $supportActiveAmount = (float) (clone $supportActiveQuery)->sum('sales_opportunities.amount');
        $previousSupportActiveCount = (clone $previousSupportActiveQuery)->count();
        $previousSupportActiveAmount = (float) (clone $previousSupportActiveQuery)->sum('sales_opportunities.amount');

        $employeeHoursRows = TaskTimeEntry::query()
            ->selectRaw("coalesce(employees.id::text, mapped_employees.id::text, task_time_entries.employee_id::text, 'unassigned') as employee_id")
            ->selectRaw("coalesce(max(employees.name), max(mapped_employees.name), max(employee_mappings.label), 'Без сотрудника') as label, sum(task_time_entries.minutes) / 60.0 as value, count(*) as entries")
            ->leftJoin('employees', function ($join) {
                $join->whereRaw('employees.weeek_uuid::text = task_time_entries.employee_id::text');
            })
            ->leftJoinSub($this->employeeMappingsQuery(), 'employee_mappings', function ($join) {
                $join->whereRaw('employee_mappings.external_id = task_time_entries.employee_id::text');
            })
            ->leftJoin('employees as mapped_employees', 'mapped_employees.id', '=', 'employee_mappings.internal_id')
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupByRaw("coalesce(employees.id::text, mapped_employees.id::text, task_time_entries.employee_id::text, 'unassigned')")
            ->orderByDesc('value')
            ->get();

        $workedHoursTotal = round((float) $employeeHoursRows->sum('value'), 1);
        $employeeHoursSummary = $employeeHoursRows->map(function ($row) use ($workedHoursTotal) {
            $hours = round((float) $row->value, 1);

            return [
                'name' => (string) $row->label,
                'hours' => $hours,
                'entries' => (int) $row->entries,
                'share' => $workedHoursTotal > 0 ? round(($hours / $workedHoursTotal) * 100, 1) : 0,
            ];
        })->values();

        $pipelineQuery = SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->when(! empty($allowedPipelineNames), function ($query) use ($allowedPipelineNames) {
                $query->whereRaw('lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')', $allowedPipelineNames);
            }, function ($query) use ($excludedPipelineNames) {
                if (! empty($excludedPipelineNames)) {
                    $query->whereRaw('lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')', $excludedPipelineNames);
                }
            });

        $primarySalesBase = $this->primarySalesQuery($primaryPipelineName, $allowedPipelineNames, $excludedPipelineNames);
        $primarySalesPeriodBase = (clone $primarySalesBase)
            ->whereBetween(DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);

        $leadCount = (clone $primarySalesPeriodBase)->count();

        $pipelineRows = $pipelineQuery
            ->selectRaw(
                "coalesce(pipelines.name, 'Без воронки') as label,
                coalesce(pipelines.id, 0) as pipeline_id,
                count(*) as total_count,
                sum(case when coalesce(sales_opportunities.opened_at, sales_opportunities.created_at) between ? and ? then 1 else 0 end) as period_total_count,
                sum(case when sales_opportunities.status = 'open' then 1 else 0 end) as open_count,
                sum(case when sales_opportunities.status = 'open' then coalesce(sales_opportunities.amount, 0) else 0 end) as open_amount,
                sum(case when sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142' then 1 else 0 end) as won_count,
                sum(case when (sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142') and coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at) between ? and ? then 1 else 0 end) as won_period_count,
                sum(case when (sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142') and coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at) between ? and ? then coalesce(sales_opportunities.amount, 0) else 0 end) as won_period_amount,
                sum(case when sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142' then coalesce(sales_opportunities.amount, 0) else 0 end) as won_total_amount,
                sum(case when sales_opportunities.status = 'open' then coalesce(sales_opportunities.amount, 0) * coalesce(sales_opportunities.probability, 0) / 100.0 else 0 end) as forecast_amount,
                max(coalesce(sales_opportunities.last_activity_at, sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)) as last_activity_at",
                [$period->from, $period->to, $period->from, $period->to, $period->from, $period->to]
            )
            ->groupByRaw("coalesce(pipelines.id, 0), coalesce(pipelines.name, 'Без воронки')")
            ->orderByDesc('won_period_amount')
            ->get()
            ->map(function ($row) {
                $openCount = (int) $row->open_count;
                $wonCount = (int) $row->won_period_count;
                $openAmount = (float) $row->open_amount;
                $wonAmount = (float) $row->won_period_amount;
                $totalCount = (int) $row->period_total_count;
                $forecastAmount = (float) $row->forecast_amount;
                $workShare = $totalCount > 0 ? min(100, round(($openCount / $totalCount) * 100, 1)) : 0;

                return [
                    'label' => $row->label,
                    'open_count' => $openCount,
                    'open_amount' => $openAmount,
                    'won_count' => $wonCount,
                    'won_amount' => $wonAmount,
                    'total_count' => $totalCount,
                    'forecast_amount' => $forecastAmount,
                    'work_share' => $workShare,
                    'last_activity_at' => $row->last_activity_at,
                ];
            })
            ->values();

        $openDeals = $pipelineRows->sum('open_count');
        $wonDeals = $pipelineRows->sum('won_count');
        $wonRevenue = $pipelineRows->sum('won_amount');
        $forecast = $pipelineRows->sum('won_total_amount');
        $activePipelineAmount = $pipelineRows->sum('open_amount');

        $revenue = RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->sum('amount');

        $expenses = ExpenseTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->sum('amount');

        $activeProjects = Project::query()
            ->where('status', 'active')
            ->count();

        $hours = TaskTimeEntry::query()
            ->whereBetween('entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->sum(DB::raw('minutes / 60.0'));
        $previousHours = TaskTimeEntry::query()
            ->whereBetween('entry_date', [$previousPeriod->from->toDateString(), $previousPeriod->to->toDateString()])
            ->sum(DB::raw('minutes / 60.0'));

        $leadSeries = (clone $primarySalesBase)
            ->selectRaw("date_trunc('day', coalesce(sales_opportunities.opened_at, sales_opportunities.created_at))::date as date, count(*) as total")
            ->whereBetween(DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $dealSeries = SalesOpportunity::query()
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->selectRaw(
                "date_trunc('day', coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at))::date as date,
                sum(case when sales_opportunities.status = 'open' then 1 else 0 end) as open_count,
                sum(case when (sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142') and coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at) between ? and ? then 1 else 0 end) as won_count"
            , [$period->from, $period->to])
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $flowRows = CashflowEntry::query()
            ->selectRaw("entry_date, sum(case when kind = 'in' then amount when kind = 'out' then -amount else 0 end) as total")
            ->whereBetween('entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('entry_date')
            ->orderBy('entry_date')
            ->get();

        $pipelineLabels = $pipelineRows->pluck('label')->values()->all();
        $pipelineOpenCounts = $pipelineRows->pluck('open_count')->map(fn ($value) => (float) $value)->values()->all();
        $pipelineWonCounts = $pipelineRows->pluck('won_count')->map(fn ($value) => (float) $value)->values()->all();
        $pipelineOpenAmounts = $pipelineRows->pluck('open_amount')->map(fn ($value) => (float) $value)->values()->all();
        $pipelineWonAmounts = $pipelineRows->pluck('won_amount')->map(fn ($value) => (float) $value)->values()->all();
        $pipelineForecastAmounts = $pipelineRows->pluck('won_total_amount')->map(fn ($value) => (float) $value)->values()->all();

        $topPipelines = $pipelineRows->take(6)->map(function (array $row) {
            $riskTone = match (true) {
                $row['open_count'] >= 8 => 'rose',
                $row['won_amount'] >= 500000 => 'emerald',
                $row['forecast_amount'] >= 250000 => 'amber',
                default => 'slate',
            };

            return [
                'name' => $row['label'],
                'open_count' => $row['open_count'],
                'open_amount' => $row['open_amount'],
                'won_count' => $row['won_count'],
                'won_amount' => $row['won_amount'],
                'total_count' => $row['total_count'],
                'forecast_amount' => $row['forecast_amount'],
                'work_share' => $row['work_share'],
                'last_activity_at' => $row['last_activity_at'],
                'tone' => $riskTone,
            ];
        })->values();

        return [
            'overview_cards' => [
                [
                    'label' => 'Новые лиды',
                    'value' => number_format($mainCreatedCount),
                    'hint' => 'Созданные сделки в Основной',
                    'tone' => 'brand',
                    'comparison' => $this->compareValues($mainCreatedCount, $previousMainCreatedCount),
                ],
                [
                    'label' => 'Лидов в работе',
                    'value' => number_format($mainActiveCount),
                    'secondary' => number_format($mainActiveAmount, 0, ',', ' ').' ₽',
                    'hint' => 'Активные сделки в Основной',
                    'tone' => 'slate',
                    'comparison' => $this->compareValues($mainActiveCount, $previousMainActiveCount),
                ],
                [
                    'label' => 'Новых продаж',
                    'value' => number_format($mainWonCount),
                    'secondary' => number_format($mainWonAmount, 0, ',', ' ').' ₽',
                    'hint' => 'Закрыто успешно в Основной',
                    'tone' => 'emerald',
                    'comparison' => $this->compareValues($mainWonCount, $previousMainWonCount),
                ],
                [
                    'label' => 'Повторных продаж',
                    'value' => number_format($repeatWonCount),
                    'secondary' => number_format($repeatWonAmount, 0, ',', ' ').' ₽',
                    'hint' => 'Закрыто успешно в Повторной',
                    'tone' => 'cyan',
                    'comparison' => $this->compareValues($repeatWonCount, $previousRepeatWonCount),
                ],
                [
                    'label' => 'В сопровождении',
                    'value' => number_format($supportActiveCount),
                    'secondary' => number_format($supportActiveAmount, 0, ',', ' ').' ₽',
                    'hint' => 'Активные сделки в Сопровождении',
                    'tone' => 'amber',
                    'comparison' => $this->compareValues($supportActiveCount, $previousSupportActiveCount),
                ],
            ],
            'hours' => [
                'total' => $workedHoursTotal,
                'previous_total' => round((float) $previousHours, 1),
                'items' => $employeeHoursSummary->take(10)->all(),
            ],
            'kpis' => [
                ['label' => 'Новые сделки в Основной', 'value' => number_format($leadCount), 'hint' => 'За выбранный период', 'tone' => 'brand'],
                ['label' => 'Сделки в работе', 'value' => number_format($openDeals), 'hint' => 'Открытая воронка сейчас', 'tone' => 'slate'],
                ['label' => 'Успешные сделки', 'value' => number_format($wonDeals), 'hint' => 'Выиграно за период', 'tone' => 'emerald'],
                ['label' => 'Выручка по выигранным', 'value' => number_format($wonRevenue, 0, ',', ' '), 'hint' => 'Сумма выигранных сделок', 'tone' => 'cyan'],
                ['label' => 'Прогноз продаж', 'value' => number_format($forecast, 0, ',', ' '), 'hint' => 'Открытые сделки с учетом вероятности', 'tone' => 'amber'],
                ['label' => 'Денежный результат', 'value' => number_format($revenue - $expenses, 0, ',', ' '), 'hint' => 'Поступления минус расходы', 'tone' => 'rose'],
            ],
            'signals' => [
                'tracked_hours' => round($hours, 1),
                'margin' => $revenue > 0 ? round((($revenue - $expenses) / $revenue) * 100, 1) : 0,
                'active_pipeline_amount' => $activePipelineAmount,
                'won_revenue' => $wonRevenue,
                'forecast' => $forecast,
            ],
            'charts' => [
                'pipeline_work_mix' => [
                    'labels' => $pipelineLabels,
                    'datasets' => [
                        [
                            'label' => 'В работе',
                            'data' => $pipelineOpenCounts,
                            'backgroundColor' => 'rgba(56, 189, 248, 0.45)',
                            'borderColor' => '#38bdf8',
                        ],
                        [
                            'label' => 'Успешные',
                            'data' => $pipelineWonCounts,
                            'backgroundColor' => 'rgba(34, 197, 94, 0.45)',
                            'borderColor' => '#22c55e',
                        ],
                    ],
                    'options' => ['stacked' => true],
                ],
                'pipeline_value_mix' => [
                    'labels' => $pipelineLabels,
                    'datasets' => [
                        [
                            'label' => 'В работе, ₽',
                            'data' => $pipelineOpenAmounts,
                            'backgroundColor' => 'rgba(139, 92, 246, 0.38)',
                            'borderColor' => '#8b5cf6',
                        ],
                        [
                            'label' => 'Продажи, ₽',
                            'data' => $pipelineWonAmounts,
                            'backgroundColor' => 'rgba(245, 158, 11, 0.42)',
                            'borderColor' => '#f59e0b',
                        ],
                    ],
                    'options' => ['stacked' => true],
                ],
                'lead_vs_sales' => [
                    'labels' => $this->dailySeries($period, $leadSeries, 'date', 'total')['labels'],
                    'datasets' => [
                        [
                            'label' => 'Основная',
                            'data' => $this->dailySeries($period, $leadSeries, 'date', 'total')['values'],
                            'borderColor' => '#38bdf8',
                            'backgroundColor' => 'rgba(56, 189, 248, 0.15)',
                        ],
                        [
                            'label' => 'Успешные сделки',
                            'data' => $this->dailySeries($period, $dealSeries, 'date', 'won_count')['values'],
                            'borderColor' => '#22c55e',
                            'backgroundColor' => 'rgba(34, 197, 94, 0.15)',
                        ],
                    ],
                ],
                'forecast_by_pipeline' => [
                    'labels' => $pipelineLabels,
                    'values' => $pipelineForecastAmounts,
                ],
                'cashflow_series' => $this->dailySeries($period, $flowRows, 'entry_date', 'total'),
            ],
            'pipeline_cards' => $topPipelines,
            'funnel' => [
                ['label' => 'Новые сделки в Основной', 'value' => $leadCount],
                ['label' => 'Сделки в работе', 'value' => $openDeals],
                ['label' => 'Успешные сделки', 'value' => $wonDeals],
            ],
            'period' => $period,
        ];
    }

    protected function primaryPipelineName(): string
    {
        return trim((string) config('dashboard.primary_sales_pipeline_name', 'Основная'));
    }

    protected function repeatPipelineName(): string
    {
        return trim((string) config('dashboard.repeat_sales_pipeline_name', 'Повторные'));
    }

    protected function supportPipelineName(): string
    {
        return trim((string) config('dashboard.support_pipeline_name', 'Сопровождение'));
    }

    protected function pipelineQueryByName(string $pipelineName, array $allowedPipelineNames, array $excludedPipelineNames)
    {
        $query = SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($pipelineName)]);

        if (! empty($allowedPipelineNames)) {
            $query->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')',
                $allowedPipelineNames
            );
        } elseif (! empty($excludedPipelineNames)) {
            $query->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')',
                $excludedPipelineNames
            );
        }

        return $query;
    }

    protected function primarySalesQuery(string $primaryPipelineName, array $allowedPipelineNames, array $excludedPipelineNames)
    {
        $query = SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($primaryPipelineName)]);

        if (! empty($allowedPipelineNames)) {
            $query->whereRaw('lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')', $allowedPipelineNames);
        } elseif (! empty($excludedPipelineNames)) {
            $query->whereRaw('lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')', $excludedPipelineNames);
        }

        return $query;
    }

    protected function employeeMappingsQuery()
    {
        return DB::table('source_mappings')
            ->selectRaw('external_id, max(label) as label, max(internal_id) as internal_id')
            ->where('source_key', 'weeek')
            ->where('external_type', 'user')
            ->where('internal_type', Employee::class)
            ->groupBy('external_id');
    }
}
