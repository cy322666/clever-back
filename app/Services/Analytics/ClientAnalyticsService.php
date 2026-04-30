<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Models\SourceConnection;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ClientAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $company = $this->company();
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
        $defaultAmoBaseUrl = SourceConnection::query()
            ->whereIn('source_key', ['amo', 'amocrm'])
            ->select('settings')
            ->get()
            ->map(fn (SourceConnection $connection): ?string => $this->amoBaseUrl($connection->settings))
            ->filter()
            ->first()
            ?? SourceConnection::query()
                ->select('settings')
                ->get()
                ->map(fn (SourceConnection $connection): ?string => $this->amoBaseUrl($connection->settings))
                ->filter()
                ->first();

        $leadRows = $this->leadQuery($allowedPipelineNames, $excludedPipelineNames)
            ->selectRaw($this->companyIdSql('sales_leads').' as company_id, count(*) as lead_count, sum(coalesce(budget_amount, 0)) as lead_budget, max(lead_created_at) as last_lead_at')
            ->whereBetween('lead_created_at', [$period->from, $period->to])
            ->groupByRaw($this->companyIdSql('sales_leads'))
            ->get()
            ->keyBy('company_id');

        $dealRows = $this->opportunityQuery($allowedPipelineNames, $excludedPipelineNames)
            ->selectRaw($this->companyIdSql('sales_opportunities')." as company_id,
                count(*) as deal_count,
                sum(case when status = 'won' then 1 else 0 end) as won_count,
                sum(case when status = 'lost' then 1 else 0 end) as lost_count,
                sum(case when status = 'open' then 1 else 0 end) as open_count,
                sum(case when status = 'won' then amount else 0 end) as won_revenue,
                avg(amount) as avg_deal_amount,
                max(last_activity_at) as last_deal_at")
            ->whereBetween('opened_at', [$period->from, $period->to])
            ->groupByRaw($this->companyIdSql('sales_opportunities'))
            ->get()
            ->keyBy('company_id');

        $latestDealNameRows = $this->opportunityQuery($allowedPipelineNames, $excludedPipelineNames)
            ->selectRaw($this->companyIdSql('sales_opportunities').", (array_agg(sales_opportunities.name order by coalesce(sales_opportunities.last_activity_at, sales_opportunities.opened_at, sales_opportunities.created_at) desc))[1] as latest_deal_name")
            ->whereBetween('opened_at', [$period->from, $period->to])
            ->groupByRaw($this->companyIdSql('sales_opportunities'))
            ->get()
            ->keyBy('company_id');

        $channelRows = $this->leadQuery($allowedPipelineNames, $excludedPipelineNames)
            ->selectRaw($this->companyIdSql('sales_leads').' as company_id, source_channel')
            ->whereBetween('lead_created_at', [$period->from, $period->to])
            ->whereNotNull('source_channel')
            ->get()
            ->groupBy('company_id')
            ->map(fn (Collection $rows) => $rows->pluck('source_channel')->filter()->unique()->values()->all());

        $dealCompanyIds = $dealRows->keys()
            ->filter(fn ($value) => (int) $value > 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $baseClients = Client::query()
            ->whereIn('company_id', $dealCompanyIds)
            ->with('owner')
            ->orderBy('name')
            ->get();

        $rows = $baseClients->map(function (Client $client) use ($leadRows, $dealRows, $latestDealNameRows, $channelRows, $period, $defaultAmoBaseUrl) {
            $companyId = (int) ($client->company_id ?? 0);
            $leadRow = $leadRows->get($companyId);
            $dealRow = $dealRows->get($companyId);
            $latestDealNameRow = $latestDealNameRows->get($companyId);

            $leadCount = (int) ($leadRow->lead_count ?? 0);
            $dealCount = (int) ($dealRow->deal_count ?? 0);
            $wonCount = (int) ($dealRow->won_count ?? 0);
            $lostCount = (int) ($dealRow->lost_count ?? 0);
            $openCount = (int) ($dealRow->open_count ?? 0);
            $wonRevenue = (float) ($dealRow->won_revenue ?? 0);
            $avgDeal = (float) ($dealRow->avg_deal_amount ?? 0);

            $lastLead = ! empty($leadRow?->last_lead_at) ? CarbonImmutable::parse($leadRow->last_lead_at) : null;
            $lastDeal = ! empty($dealRow?->last_deal_at) ? CarbonImmutable::parse($dealRow->last_deal_at) : null;
            $lastActivity = collect([$lastLead, $lastDeal])
                ->filter()
                ->sortByDesc(fn (CarbonImmutable $date) => $date->getTimestamp())
                ->first();

            $daysSinceActivity = $lastActivity ? (int) $lastActivity->diffInDays($period->to->endOfDay()) : null;
            $winRate = $dealCount > 0 ? round(($wonCount / $dealCount) * 100, 1) : 0;
            $sourceChannels = $channelRows->get($companyId, []);
            $segment = $this->segmentForClient($client, $wonRevenue, $dealCount, $wonCount, $daysSinceActivity, $winRate, $openCount);
            $displayName = $this->displayCompanyName($client, $latestDealNameRow?->latest_deal_name ?? null);

            return [
                'id' => $client->id,
                'company_id' => $companyId,
                'name' => $displayName,
                'display_name' => $displayName,
                'amo_entity_type' => 'company',
                'amo_entity_label' => 'Компания',
                'entity_url' => $this->amoCompanyUrl($defaultAmoBaseUrl, $companyId),
                'owner' => $client->owner?->name ?? '—',
                'risk_level' => $client->risk_level,
                'support_classification' => $client->support_classification,
                'segment' => $segment['label'],
                'segment_tone' => $segment['tone'],
                'lead_count' => $leadCount,
                'deal_count' => $dealCount,
                'won_count' => $wonCount,
                'lost_count' => $lostCount,
                'open_count' => $openCount,
                'won_revenue' => $wonRevenue,
                'avg_deal_amount' => $avgDeal,
                'win_rate' => $winRate,
                'days_since_activity' => $daysSinceActivity,
                'last_activity_at' => $lastActivity?->toDateTimeString(),
                'source_channels' => $sourceChannels,
                'source_channels_label' => $sourceChannels ? implode(', ', $sourceChannels) : '—',
                'annual_revenue_estimate' => (float) ($client->annual_revenue_estimate ?? 0),
            ];
        })->sortByDesc('won_revenue')->values();

        $segmentCounts = $rows->countBy('segment')->sortDesc();
        $activityBands = collect([
            '0-7' => 0,
            '8-14' => 0,
            '15-30' => 0,
            '30+' => 0,
        ]);

        foreach ($rows as $row) {
            $band = $this->activityBand((int) ($row['days_since_activity'] ?? 999));
            $activityBands[$band] = ($activityBands[$band] ?? 0) + 1;
        }

        $activeClients = $rows->filter(fn (array $row) => $row['lead_count'] > 0 || $row['deal_count'] > 0 || $row['won_revenue'] > 0)->count();
        $riskClients = $rows->filter(fn (array $row) => in_array($row['segment'], ['At risk', 'Watch'], true) || in_array($row['risk_level'], ['high', 'critical'], true))->count();
        $vipClients = $rows->where('segment', 'VIP')->count();
        $staleClients = $rows->filter(fn (array $row) => ($row['days_since_activity'] ?? 999) >= 30)->count();
        $wonRevenue = $rows->sum('won_revenue');
        $avgWinRate = $rows->filter(fn (array $row) => $row['deal_count'] > 0)->avg('win_rate') ?? 0;

        $sourceMix = $this->leadQuery($allowedPipelineNames, $excludedPipelineNames)
            ->selectRaw('source_channel, count(*) as total')
            ->whereBetween('lead_created_at', [$period->from, $period->to])
            ->whereNotNull('source_channel')
            ->groupBy('source_channel')
            ->orderByDesc('total')
            ->limit(6)
            ->get();

        $companyCount = $rows->count();
        $dealTotal = (int) $rows->sum('deal_count');
        $salesTotal = (int) $rows->sum('won_count');
        $revenueTotal = (float) $rows->sum('won_revenue');
        $activeCompanyCount = $rows->filter(fn (array $row) => $row['deal_count'] > 0 || $row['won_count'] > 0 || $row['won_revenue'] > 0)->count();
        $averageDealAmount = $salesTotal > 0 ? $revenueTotal / $salesTotal : 0;

        return [
            'kpis' => [
                ['label' => 'Компаний из amo', 'value' => number_format($companyCount), 'hint' => 'Только компании с нужными сделками', 'tone' => 'brand'],
                ['label' => 'Сделок', 'value' => number_format($dealTotal), 'hint' => 'Все сделки по компаниям', 'tone' => 'slate'],
                ['label' => 'Продаж', 'value' => number_format($salesTotal), 'hint' => 'Закрыто успешно', 'tone' => 'emerald'],
                ['label' => 'Сумма продаж', 'value' => number_format($revenueTotal, 0, ',', ' '), 'hint' => 'Выигранные сделки', 'tone' => 'cyan'],
                ['label' => 'Активных компаний', 'value' => number_format($activeCompanyCount), 'hint' => 'Есть сделки или выручка', 'tone' => 'amber'],
                ['label' => 'Средний чек', 'value' => number_format($averageDealAmount, 0, ',', ' '), 'hint' => 'По выигранным сделкам', 'tone' => 'rose'],
            ],
            'companies' => $rows->all(),
            'clients' => $rows->all(),
            'period' => $period,
        ];
    }

    protected function amoBaseUrl(mixed $settings): ?string
    {
        $baseUrl = trim((string) data_get(is_array($settings) ? $settings : [], 'base_url', ''));

        return $baseUrl !== '' ? rtrim($baseUrl, '/') : null;
    }

    protected function amoCompanyUrl(?string $baseUrl, int $companyId): ?string
    {
        if ($baseUrl === null || $companyId <= 0) {
            return null;
        }

        return $baseUrl.'/companies/detail/'.$companyId;
    }

    /**
     * @return array{label: string, tone: string}
     */
    protected function segmentForClient(Client $client, float $wonRevenue, int $dealCount, int $wonCount, ?int $daysSinceActivity, float $winRate, int $openCount): array
    {
        if ($wonRevenue >= 1_500_000 || ($wonCount >= 3 && $winRate >= 40)) {
            return ['label' => 'VIP', 'tone' => 'green'];
        }

        if (in_array($client->risk_level, ['high', 'critical'], true) || (($daysSinceActivity ?? 999) >= 21 && $dealCount > 0) || ($openCount >= 3 && $winRate < 20)) {
            return ['label' => 'At risk', 'tone' => 'red'];
        }

        if ($wonRevenue >= 350_000 || $dealCount >= 3 || $winRate >= 30) {
            return ['label' => 'Growth', 'tone' => 'blue'];
        }

        if (($daysSinceActivity ?? 999) >= 14) {
            return ['label' => 'Watch', 'tone' => 'yellow'];
        }

        return ['label' => 'Stable', 'tone' => 'slate'];
    }

    protected function activityBand(int $daysSinceActivity): string
    {
        return match (true) {
            $daysSinceActivity <= 7 => '0-7',
            $daysSinceActivity <= 14 => '8-14',
            $daysSinceActivity <= 30 => '15-30',
            default => '30+',
        };
    }

    protected function displayCompanyName(Client $client, ?string $fallbackName = null): string
    {
        $name = $this->cleanCompanyDisplayName($client->name);

        if (! $this->isPlaceholderAmoName($name)) {
            return $name;
        }

        $metadataName = $this->cleanCompanyDisplayName(data_get($client->metadata, 'amo_company.name', ''));

        if ($metadataName !== '' && ! $this->isPlaceholderAmoName($metadataName)) {
            return $metadataName;
        }

        $fallbackName = $this->cleanCompanyDisplayName($fallbackName);

        if ($fallbackName !== '' && ! $this->isPlaceholderAmoName($fallbackName)) {
            return $fallbackName;
        }

        return 'Компания #'.$client->id;
    }

    protected function isPlaceholderAmoName(?string $name): bool
    {
        $name = trim((string) $name);

        if ($name === '') {
            return true;
        }

        return (bool) preg_match('/^(amoCRM (company|buyer|lead) #\d+|Заявка №\d+|Сделка #\d+|Автосделка: .*|amoCRM lead #\d+)$/u', $name);
    }

    protected function cleanCompanyDisplayName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        $name = preg_replace('/^ИНДИВИДУАЛЬНЫЙ\s+ПРЕДПРИНИМАТЕЛЬ\s+/ui', '', $name) ?? $name;
        $name = preg_replace('/^ИП\s+/ui', '', $name) ?? $name;
        $name = preg_replace('/^ОБЩЕСТВО\s+С\s+ОГРАНИЧЕННОЙ\s+ОТВЕТСТВЕННОСТЬЮ\s+/ui', '', $name) ?? $name;
        $name = preg_replace('/^ООО\s+/ui', '', $name) ?? $name;

        return trim($name);
    }

    protected function leadQuery(array $allowedPipelineNames, array $excludedPipelineNames)
    {
        $query = SalesLead::query()->leftJoin('pipelines', 'pipelines.id', '=', 'sales_leads.pipeline_id');
        $this->applyPipelineFilterToLeadQuery($query, $allowedPipelineNames, $excludedPipelineNames);

        return $query;
    }

    protected function opportunityQuery(array $allowedPipelineNames, array $excludedPipelineNames)
    {
        $query = SalesOpportunity::query()->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id');
        $this->applyPipelineFilterToOpportunityQuery($query, $allowedPipelineNames, $excludedPipelineNames);

        return $query;
    }

    protected function companyIdSql(string $table): string
    {
        return "coalesce(nullif({$table}.metadata #>> '{amo_lead,companies,0,id}', '')::bigint, 0)";
    }

    protected function applyPipelineFilterToLeadQuery($query, array $allowedPipelineNames, array $excludedPipelineNames): void
    {
        $query
            ->when(! empty($allowedPipelineNames), function ($query) use ($allowedPipelineNames) {
                $query->whereRaw('lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')', $allowedPipelineNames);
            }, function ($query) use ($excludedPipelineNames) {
                if (! empty($excludedPipelineNames)) {
                    $query->whereRaw('lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')', $excludedPipelineNames);
                }
            });
    }

    protected function applyPipelineFilterToOpportunityQuery($query, array $allowedPipelineNames, array $excludedPipelineNames): void
    {
        $query
            ->when(! empty($allowedPipelineNames), function ($query) use ($allowedPipelineNames) {
                $query->whereRaw('lower(coalesce(pipelines.name, \'\')) in ('.implode(',', array_fill(0, count($allowedPipelineNames), '?')).')', $allowedPipelineNames);
            }, function ($query) use ($excludedPipelineNames) {
                if (! empty($excludedPipelineNames)) {
                    $query->whereRaw('lower(coalesce(pipelines.name, \'\')) not in ('.implode(',', array_fill(0, count($excludedPipelineNames), '?')).')', $excludedPipelineNames);
                }
            });
    }
}
