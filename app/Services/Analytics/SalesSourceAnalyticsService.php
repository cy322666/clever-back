<?php

namespace App\Services\Analytics;

use App\Models\Pipeline;
use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SalesSourceAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period, array $filters = []): array
    {
        $rows = $this->rows($period);
        $filteredRows = $this->applyFilters($rows, $filters);

        return [
            'kpis' => $this->kpis($filteredRows),
            'rows' => $filteredRows->values()->all(),
            'all_rows' => $rows->values()->all(),
            'filter_options' => $this->filterOptions($rows),
            'period' => $period,
        ];
    }

    protected function rows(AnalyticsPeriod $period): Collection
    {
        $sourceNames = collect([
            'Сарафан',
            'Партнёры',
            'Биржи',
            'Сайт',
            'Контент',
            'Повторные продажи',
            'Другое',
        ]);

        $leadRows = $this->leadRows($period);
        $dealRows = $this->dealRows($period);

        return $sourceNames
            ->merge($leadRows->keys())
            ->merge($dealRows->keys())
            ->unique()
            ->values()
            ->map(function (string $source, int $index) use ($leadRows, $dealRows): array {
                $leadRow = $leadRows->get($source);
                $dealRow = $dealRows->get($source);
                $newLeads = (int) ($leadRow?->new_leads ?? 0);
                $qualifiedLeads = (int) ($leadRow?->qualified_leads ?? 0);
                $proposalSent = (int) ($dealRow?->proposal_sent ?? 0);
                $won = (int) ($dealRow?->won_count ?? 0);
                $lost = (int) ($dealRow?->lost_count ?? 0);
                $revenue = (float) ($dealRow?->revenue ?? 0);
                $pipeline = (float) ($dealRow?->pipeline ?? 0);
                $forecast = (float) ($dealRow?->forecast ?? 0);
                $avgCycleDays = $dealRow?->avg_cycle_days !== null ? (float) $dealRow->avg_cycle_days : null;
                $conversion = $newLeads > 0 ? ($won / $newLeads) * 100 : null;
                $averageCheck = $won > 0 ? $revenue / $won : 0;
                $status = $this->status($newLeads, $won, $conversion, $averageCheck);

                return [
                    '__key' => 'sales-source-'.$index.'-'.md5($source),
                    'source' => $source,
                    'source_key' => $source,
                    'pipeline_key' => (string) ($dealRow?->pipeline_key ?? $leadRow?->pipeline_key ?? 'all'),
                    'pipeline_name' => (string) ($dealRow?->pipeline_name ?? $leadRow?->pipeline_name ?? 'Все воронки'),
                    'new_leads' => $newLeads,
                    'qualified_leads' => $qualifiedLeads,
                    'proposal_sent' => $proposalSent,
                    'won_count' => $won,
                    'lost_count' => $lost,
                    'conversion' => $conversion,
                    'revenue' => $revenue,
                    'average_check' => $averageCheck,
                    'pipeline' => $pipeline,
                    'forecast' => $forecast,
                    'avg_cycle_days' => $avgCycleDays,
                    'loss_reasons' => (string) ($dealRow?->loss_reasons ?: 'Нет данных'),
                    'status' => $status['label'],
                    'status_key' => $status['key'],
                    'tone' => $status['tone'],
                ];
            })
            ->sortBy([
                fn (array $left, array $right): int => (float) $right['revenue'] <=> (float) $left['revenue'],
                fn (array $left, array $right): int => (float) $right['forecast'] <=> (float) $left['forecast'],
                fn (array $left, array $right): int => (int) $right['won_count'] <=> (int) $left['won_count'],
            ])
            ->values();
    }

    protected function leadRows(AnalyticsPeriod $period): Collection
    {
        $leadDate = DB::raw('coalesce(sales_leads.lead_created_at, sales_leads.created_at)');

        return SalesLead::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_leads.pipeline_id')
            ->whereBetween($leadDate, [$period->from, $period->to])
            ->selectRaw("
                coalesce(nullif(trim(sales_leads.source_channel), ''), 'Другое') as source,
                coalesce(pipelines.id::text, 'all') as pipeline_key,
                coalesce(pipelines.name, 'Все воронки') as pipeline_name,
                count(*) as new_leads,
                sum(case
                    when sales_leads.status_id is not null and sales_leads.status_id not in (0, 1) then 1
                    when lower(coalesce(sales_leads.name, '')) like '%квал%' then 1
                    else 0
                end) as qualified_leads
            ")
            ->groupByRaw("coalesce(nullif(trim(sales_leads.source_channel), ''), 'Другое'), coalesce(pipelines.id::text, 'all'), coalesce(pipelines.name, 'Все воронки')")
            ->get()
            ->keyBy('source');
    }

    protected function dealRows(AnalyticsPeriod $period): Collection
    {
        $sourceSub = SalesLead::query()
            ->selectRaw("distinct on (external_id) external_id as lead_external_id, coalesce(nullif(source_channel, ''), 'Другое') as source_channel")
            ->orderBy('external_id')
            ->orderByDesc('lead_created_at')
            ->orderByDesc('created_at');

        return SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->leftJoinSub($sourceSub, 'lead_sources', function ($join) {
                $join->whereRaw("lead_sources.lead_external_id = (sales_opportunities.metadata #>> '{amo_lead,id}')");
            })
            ->whereBetween(DB::raw('coalesce(sales_opportunities.closed_at, sales_opportunities.won_at, sales_opportunities.lost_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to])
            ->selectRaw("
                coalesce(nullif(trim(lead_sources.source_channel), ''), nullif(trim(sales_opportunities.source_channel), ''), 'Другое') as source,
                coalesce(pipelines.id::text, 'all') as pipeline_key,
                coalesce(pipelines.name, 'Все воронки') as pipeline_name,
                count(*) as deals,
                sum(case
                    when lower(coalesce(stages.name, '')) like '%кп%'
                        or lower(coalesce(stages.name, '')) like '%предлож%'
                    then 1 else 0
                end) as proposal_sent,
                sum(case when {$this->wonSql()} then 1 else 0 end) as won_count,
                sum(case when {$this->lostSql()} then 1 else 0 end) as lost_count,
                sum(case when {$this->wonSql()} then sales_opportunities.amount else 0 end) as revenue,
                sum(case when sales_opportunities.status = 'open' then sales_opportunities.amount else 0 end) as pipeline,
                sum(case when sales_opportunities.status = 'open' then sales_opportunities.amount * {$this->probabilitySql()} / 100.0 else 0 end) as forecast,
                avg(case when {$this->wonSql()} and coalesce(sales_opportunities.closed_at, sales_opportunities.won_at) is not null and sales_opportunities.opened_at is not null
                    then extract(epoch from (coalesce(sales_opportunities.closed_at, sales_opportunities.won_at) - sales_opportunities.opened_at)) / 86400.0
                    else null
                end) as avg_cycle_days,
                string_agg(distinct nullif(trim(coalesce(sales_opportunities.closed_reason, sales_opportunities.metadata #>> '{amo_lead,loss_reason}', sales_opportunities.metadata #>> '{amo_lead,loss_reason_name}')), ''), ', ') as loss_reasons
            ")
            ->groupByRaw("
                coalesce(nullif(trim(lead_sources.source_channel), ''), nullif(trim(sales_opportunities.source_channel), ''), 'Другое'),
                coalesce(pipelines.id::text, 'all'),
                coalesce(pipelines.name, 'Все воронки')
            ")
            ->get()
            ->keyBy('source');
    }

    protected function kpis(Collection $rows): array
    {
        $leads = (int) $rows->sum('new_leads');
        $won = (int) $rows->sum('won_count');
        $revenue = (float) $rows->sum('revenue');
        $conversion = $leads > 0 ? ($won / $leads) * 100 : null;
        $bestByRevenue = $rows->sortByDesc('revenue')->first();
        $bestByConversion = $rows
            ->filter(fn (array $row): bool => (int) $row['new_leads'] > 0 && (int) $row['won_count'] > 0)
            ->sortByDesc('conversion')
            ->first();

        return [
            ['label' => 'Всего лидов', 'value' => number_format($leads), 'hint' => 'Новые лиды за период', 'tone' => $leads > 0 ? 'brand' : 'gray'],
            ['label' => 'Всего выигранных сделок', 'value' => number_format($won), 'hint' => 'Оплаты за период', 'tone' => $won > 0 ? 'success' : 'gray'],
            ['label' => 'Общая конверсия', 'value' => $conversion === null ? 'Нет данных' : number_format($conversion, 1, ',', ' ').'%', 'hint' => 'Выигранные / лиды', 'tone' => $conversion === null ? 'gray' : ($conversion >= 20 ? 'success' : ($conversion >= 8 ? 'warning' : 'danger'))],
            ['label' => 'Общая выручка', 'value' => number_format($revenue, 0, ',', ' ').' ₽', 'hint' => 'По выигранным сделкам', 'tone' => $revenue > 0 ? 'emerald' : 'gray'],
            ['label' => 'Лучший источник по выручке', 'value' => (string) ($bestByRevenue['source'] ?? 'Нет данных'), 'hint' => isset($bestByRevenue['revenue']) ? number_format((float) $bestByRevenue['revenue'], 0, ',', ' ').' ₽' : null, 'tone' => ($bestByRevenue['revenue'] ?? 0) > 0 ? 'success' : 'gray'],
            ['label' => 'Лучший источник по конверсии', 'value' => (string) ($bestByConversion['source'] ?? 'Нет данных'), 'hint' => isset($bestByConversion['conversion']) ? number_format((float) $bestByConversion['conversion'], 1, ',', ' ').'%' : null, 'tone' => $bestByConversion ? 'success' : 'gray'],
        ];
    }

    protected function applyFilters(Collection $rows, array $filters): Collection
    {
        $pipeline = data_get($filters, 'pipeline.value');
        $source = data_get($filters, 'source.value');
        $hasLeads = data_get($filters, 'has_leads.value');
        $hasSales = data_get($filters, 'has_sales.value');

        return $rows
            ->when(filled($pipeline), fn (Collection $rows) => $rows->where('pipeline_key', (string) $pipeline))
            ->when(filled($source), fn (Collection $rows) => $rows->where('source_key', (string) $source))
            ->when($hasLeads === true || $hasLeads === '1' || $hasLeads === 1, fn (Collection $rows) => $rows->filter(fn (array $row): bool => (int) $row['new_leads'] > 0))
            ->when($hasSales === true || $hasSales === '1' || $hasSales === 1, fn (Collection $rows) => $rows->filter(fn (array $row): bool => (int) $row['won_count'] > 0))
            ->values();
    }

    protected function filterOptions(Collection $rows): array
    {
        return [
            'pipelines' => Pipeline::query()->orderBy('name')->pluck('name', 'id')->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])->all(),
            'sources' => $rows->pluck('source', 'source_key')->filter()->unique()->sort()->all(),
        ];
    }

    protected function status(int $leads, int $won, ?float $conversion, float $averageCheck): array
    {
        if ($leads === 0 && $won === 0) {
            return ['key' => 'no_data', 'label' => 'Нет данных', 'tone' => 'gray'];
        }

        if ($won > 0 && ($conversion ?? 0) >= 15 && $averageCheck >= 100000) {
            return ['key' => 'good', 'label' => 'Хороший источник', 'tone' => 'success'];
        }

        if ($leads >= 5 && ($conversion ?? 0) < 10) {
            return ['key' => 'low_sales', 'label' => 'Много лидов, мало продаж', 'tone' => 'warning'];
        }

        if ($won > 0 && $leads <= max(2, $won * 2)) {
            return ['key' => 'quality', 'label' => 'Деньги есть, лидов мало', 'tone' => 'cyan'];
        }

        return ['key' => 'watch', 'label' => 'Наблюдать', 'tone' => 'gray'];
    }

    protected function wonSql(): string
    {
        return "(sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142')";
    }

    protected function lostSql(): string
    {
        return "(sales_opportunities.status = 'lost' or stages.is_failure = true or stages.external_id = '143')";
    }

    protected function probabilitySql(): string
    {
        $stageProbability = Schema::hasColumn('stages', 'probability') ? 'stages.probability' : 'null';

        return "coalesce(nullif(sales_opportunities.probability, 0), {$stageProbability}, {$this->stageProbabilityFallbackSql()}, 0)";
    }

    protected function stageProbabilityFallbackSql(): string
    {
        $cases = collect(config('dashboard.sales_stage_probability_map', []))
            ->map(fn ($probability, $needle): string => "when lower(coalesce(stages.name, '')) like '%".str_replace("'", "''", Str::lower((string) $needle))."%' then ".(float) $probability)
            ->implode(' ');

        return $cases !== '' ? "case {$cases} else 0 end" : '0';
    }
}
