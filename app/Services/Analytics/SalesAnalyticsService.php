<?php

namespace App\Services\Analytics;

use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Models\Project;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SalesAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $previousPeriod = $period->previousComparable();
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

        $salesBase = SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id');
        $this->applyPipelineFilter($salesBase, $allowedPipelineNames, $excludedPipelineNames);

        $wonPipelineNames = array_values(array_filter([
            $primaryPipelineName,
            $this->repeatPipelineName(),
        ]));

        $wonDeals = (clone $salesBase)
            ->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($wonPipelineNames), '?')).')',
                array_map(fn (string $value): string => Str::lower(trim($value)), $wonPipelineNames)
            )
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);

        $lostDeals = (clone $salesBase)
            ->where(function ($query) {
                $query->where('sales_opportunities.status', 'lost')
                    ->orWhere('stages.is_failure', true)
                    ->orWhere('stages.external_id', '143');
            })
            ->whereBetween(DB::raw('coalesce(sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);

        $wonAmount = (float) $wonDeals->sum('amount');
        $wonCount = (clone $wonDeals)->count();
        $lostCount = (clone $lostDeals)->count();
        $totalClosed = max(1, $wonCount + $lostCount);
        $previousWonDeals = (clone $salesBase)
            ->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($wonPipelineNames), '?')).')',
                array_map(fn (string $value): string => Str::lower(trim($value)), $wonPipelineNames)
            )
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to]);
        $previousWonCount = (clone $previousWonDeals)->count();
        $previousWonAmount = (float) (clone $previousWonDeals)->sum('amount');

        $previousLostDeals = (clone $salesBase)
            ->where(function ($query) {
                $query->where('sales_opportunities.status', 'lost')
                    ->orWhere('stages.is_failure', true)
                    ->orWhere('stages.external_id', '143');
            })
            ->whereBetween(DB::raw('coalesce(sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to]);
        $previousTotalClosed = max(1, $previousWonCount + (clone $previousLostDeals)->count());

        $primarySalesBase = $this->primarySalesQuery($primaryPipelineName);
        $primarySalesPeriodBase = (clone $primarySalesBase)
            ->whereBetween(DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);
        $previousPrimarySalesPeriodBase = (clone $primarySalesBase)
            ->whereBetween(DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to]);

        $primaryWonDealsPeriodBase = (clone $primarySalesBase)
            ->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($wonPipelineNames), '?')).')',
                array_map(fn (string $value): string => Str::lower(trim($value)), $wonPipelineNames)
            )
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);
        $previousPrimaryWonDealsPeriodBase = (clone $primarySalesBase)
            ->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($wonPipelineNames), '?')).')',
                array_map(fn (string $value): string => Str::lower(trim($value)), $wonPipelineNames)
            )
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to]);

        $repeatPipelineBase = $this->repeatPipelineName() !== ''
            ? (clone $salesBase)->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($this->repeatPipelineName())])
            : (clone $salesBase);
        $repeatWonDealsPeriodBase = (clone $repeatPipelineBase)
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);
        $previousRepeatWonDealsPeriodBase = (clone $repeatPipelineBase)
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to]);

        $supportPipelineBase = (clone $salesBase)
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($this->supportPipelineName())]);
        $supportOpenAmount = (float) (clone $supportPipelineBase)
            ->where('sales_opportunities.status', 'open')
            ->sum('sales_opportunities.amount');
        $supportProjectCount = (int) Project::query()
            ->where('project_type', 'support_monthly')
            ->count();

        $sourceChannelBase = $this->latestLeadSourceChannelQuery();

        $sources = (clone $primarySalesPeriodBase)
            ->leftJoinSub($sourceChannelBase, 'lead_sources', function ($join) {
                $join->whereRaw("lead_sources.lead_external_id = (sales_opportunities.metadata #>> '{amo_lead,id}')");
            })
            ->selectRaw("coalesce(nullif(lead_sources.source_channel, ''), 'Не указан') as label, count(*) as value")
            ->groupByRaw("coalesce(nullif(lead_sources.source_channel, ''), 'Не указан')")
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        $wonSources = (clone $salesBase)
            ->leftJoinSub($sourceChannelBase, 'lead_sources', function ($join) {
                $join->whereRaw("lead_sources.lead_external_id = (sales_opportunities.metadata #>> '{amo_lead,id}')");
            })
            ->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($wonPipelineNames), '?')).')',
                array_map(fn (string $value): string => Str::lower(trim($value)), $wonPipelineNames)
            )
            ->where('stages.external_id', '142')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->selectRaw("coalesce(nullif(lead_sources.source_channel, ''), 'Не указан') as label, count(*) as value")
            ->groupByRaw("coalesce(nullif(lead_sources.source_channel, ''), 'Не указан')")
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        $conversion = $wonCount / $totalClosed * 100;
        $previousConversion = $previousWonCount / $previousTotalClosed * 100;

        $topDeals = (clone $salesBase)
            ->select('sales_opportunities.*')
            ->with(['client', 'owner'])
            ->orderByDesc('amount')
            ->limit(8)
            ->get();

        $forecast = (clone $salesBase)
            ->where('sales_opportunities.status', 'open')
            ->sum(DB::raw('sales_opportunities.amount * sales_opportunities.probability / 100.0'));
        $previousForecast = (clone $salesBase)
            ->where('sales_opportunities.status', 'open')
            ->whereBetween(DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)'), [$previousPeriod->from, $previousPeriod->to])
            ->sum(DB::raw('sales_opportunities.amount * sales_opportunities.probability / 100.0'));

        return [
            'kpis' => [
                ['label' => 'Успешные сделки в основной воронке', 'value' => number_format((clone $primaryWonDealsPeriodBase)->count()), 'hint' => 'Успешный этап 142', 'tone' => 'brand', 'comparison' => $this->compareValues((clone $primaryWonDealsPeriodBase)->count(), (clone $previousPrimaryWonDealsPeriodBase)->count())],
                ['label' => 'Успешные сделки в повторной воронке', 'value' => number_format((clone $repeatWonDealsPeriodBase)->count()), 'hint' => 'Успешный этап 142', 'tone' => 'brand', 'comparison' => $this->compareValues((clone $repeatWonDealsPeriodBase)->count(), (clone $previousRepeatWonDealsPeriodBase)->count())],
                ['label' => 'Выручка сопровождения', 'value' => number_format($supportOpenAmount, 0, ',', ' ') . ' ₽', 'hint' => 'Проектов: ' . number_format($supportProjectCount), 'tone' => 'amber'],
                ['label' => 'Выиграно сделок', 'value' => number_format($wonAmount, 0, ',', ' '), 'hint' => 'Сравнение с предыдущим периодом', 'tone' => 'emerald', 'comparison' => $this->compareValues($wonAmount, $previousWonAmount)],
                ['label' => 'Конверсия в оплату', 'value' => number_format($conversion, 1, ',', ' ').'%', 'hint' => 'Конверсия закрытия', 'tone' => 'cyan', 'comparison' => $this->compareValues($conversion, $previousConversion)],
                ['label' => 'Прогноз продаж', 'value' => number_format($forecast, 0, ',', ' '), 'hint' => 'Прогноз с учетом вероятности', 'tone' => 'amber', 'comparison' => $this->compareValues($forecast, $previousForecast)],
            ],
            'charts' => [
                'sources' => $this->namedSeries($sources),
                'sources_won' => $this->namedSeries($wonSources),
            ],
            'funnel' => [
                ['label' => 'Успешные сделки в основной воронке', 'value' => (clone $primaryWonDealsPeriodBase)->count()],
                ['label' => 'Успешные сделки в повторной воронке', 'value' => (clone $repeatWonDealsPeriodBase)->count()],
                ['label' => 'Квалификация', 'value' => (clone $salesBase)->where('sales_opportunities.status', 'qualified')->count()],
                ['label' => 'Предложение', 'value' => (clone $salesBase)->where('sales_opportunities.status', 'proposal')->count()],
                ['label' => 'Согласование', 'value' => (clone $salesBase)->where('sales_opportunities.status', 'negotiation')->count()],
                ['label' => 'Выиграно', 'value' => $wonCount],
            ],
            'top_deals' => $topDeals,
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

    protected function primarySalesQuery(string $primaryPipelineName)
    {
        return SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [Str::lower($primaryPipelineName)]);
    }

    protected function latestLeadSourceChannelQuery()
    {
        return SalesLead::query()
            ->selectRaw('distinct on (external_id) external_id as lead_external_id, source_channel')
            ->whereNotNull('source_channel')
            ->where('source_channel', '!=', '')
            ->orderBy('external_id')
            ->orderByDesc('lead_created_at')
            ->orderByDesc('created_at');
    }

    protected function applyPipelineFilter($query, array $allowedPipelineNames, array $excludedPipelineNames): void
    {
        $query->when(! empty($allowedPipelineNames), function ($query) use ($allowedPipelineNames) {
            $query->whereRaw(
                'lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')',
                $allowedPipelineNames
            );
        }, function ($query) use ($excludedPipelineNames) {
            if (! empty($excludedPipelineNames)) {
                $query->whereRaw(
                    'lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')',
                    $excludedPipelineNames
                );
            }
        });
    }
}
