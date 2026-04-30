<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Client;
use App\Models\ExpenseTransaction;
use App\Models\Project;
use App\Models\RevenueTransaction;
use App\Models\SalesOpportunity;
use App\Models\SourceConnection;
use App\Models\SupportContract;
use App\Models\TaskTimeEntry;
use App\Services\Alerts\ProjectLimitMonitorService;
use App\Services\CompanyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AlertDetectorService
{
    public function __construct(protected CompanyResolver $companyResolver) {}

    public function detect(): int
    {
        $company = $this->companyResolver->resolve();
        $count = 0;

        $idleProjectDays = (int) config('dashboard.thresholds.project_idle_days');
        $dealIdleDays = (int) config('dashboard.thresholds.deal_idle_days');
        $lowMargin = (float) config('dashboard.thresholds.low_margin_threshold');
        $now = CarbonImmutable::now();

        Project::query()
            ->where('status', 'active')
            ->where(function ($q) use ($idleProjectDays, $now) {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', $now->subDays($idleProjectDays));
            })
            ->limit(20)
            ->get()
            ->each(function (Project $project) use (&$count, $company, $now) {
                $this->upsert([
                    'source_key' => 'system',
                    'type' => 'project_idle',
                    'severity' => 'warning',
                    'status' => 'open',
                    'title' => 'Проект без активности: '.$project->name,
                    'description' => 'Нет активности дольше чем '.config('dashboard.thresholds.project_idle_days').' дней.',
                    'entity_type' => Project::class,
                    'entity_id' => $project->id,
                    'detected_at' => $now,
                    'metadata' => ['last_activity_at' => optional($project->last_activity_at)->toDateTimeString()],
                ]);
                $count++;
            });

        $count += app(ProjectLimitMonitorService::class)->refresh();

        SupportContract::query()
            ->where('status', 'active')
            ->with('usagePeriods')
            ->get()
            ->each(function (SupportContract $contract) use (&$count, $company, $now) {
                $latest = $contract->usagePeriods->sortByDesc('period_end')->first();
                if ($latest && $contract->monthly_hours_limit > 0 && $latest->actual_hours > $contract->monthly_hours_limit * config('dashboard.thresholds.support_overage_threshold')) {
                    $this->upsert([
                        'source_key' => 'system',
                        'type' => 'support_overage',
                        'severity' => 'warning',
                        'status' => 'open',
                        'title' => 'Перерасход сопровождения: '.$contract->name,
                        'description' => 'Фактические часы выше лимита.',
                        'entity_type' => SupportContract::class,
                        'entity_id' => $contract->id,
                        'detected_at' => $now,
                        'metadata' => ['actual_hours' => $latest->actual_hours, 'limit' => $contract->monthly_hours_limit],
                    ]);
                    $count++;
                }
            });

        SalesOpportunity::query()
            ->where('status', 'open')
            ->where(function ($q) use ($dealIdleDays, $now) {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', $now->subDays($dealIdleDays));
            })
            ->limit(20)
            ->get()
            ->each(function (SalesOpportunity $deal) use (&$count, $company, $now) {
                $this->upsert([
                    'source_key' => 'system',
                    'type' => 'deal_idle',
                    'severity' => 'warning',
                    'status' => 'open',
                    'title' => 'Сделка без движения: '.$deal->name,
                    'description' => 'Не было движения дольше порога.',
                    'entity_type' => SalesOpportunity::class,
                    'entity_id' => $deal->id,
                    'detected_at' => $now,
                    'metadata' => ['last_activity_at' => optional($deal->last_activity_at)->toDateTimeString()],
                ]);
                $count++;
            });

        SourceConnection::query()
            ->where(function ($q) use ($now) {
                $q->whereNull('last_synced_at')
                    ->orWhere('last_synced_at', '<', $now->subHours(36));
            })
            ->get()
            ->each(function (SourceConnection $source) use (&$count, $company, $now) {
                $this->upsert([
                    'source_key' => $source->source_key,
                    'type' => 'source_stale',
                    'severity' => 'critical',
                    'status' => 'open',
                    'title' => 'Источник давно не синкался: '.$source->name,
                    'description' => 'Проверьте доступ и настройки интеграции.',
                    'entity_type' => SourceConnection::class,
                    'entity_id' => $source->id,
                    'detected_at' => $now,
                    'metadata' => ['last_synced_at' => optional($source->last_synced_at)->toDateTimeString()],
                ]);
                $count++;
            });

        Client::query()
            ->where(function ($q) use ($lowMargin) {
                $q->whereNotNull('margin_target')->where('margin_target', '<', $lowMargin);
            })
            ->get()
            ->each(function (Client $client) use (&$count, $company, $now, $lowMargin) {
                $this->upsert([
                    'source_key' => 'system',
                    'type' => 'low_margin_client',
                    'severity' => 'warning',
                    'status' => 'open',
                    'title' => 'Низкая маржа: '.$client->name,
                    'description' => 'Маржа клиента ниже порога '.$lowMargin,
                    'entity_type' => Client::class,
                    'entity_id' => $client->id,
                    'detected_at' => $now,
                    'metadata' => ['margin_target' => $client->margin_target],
                ]);
                $count++;
            });

        $income = RevenueTransaction::query()
            ->whereBetween('posted_at', [$now->startOfMonth(), $now->endOfMonth()])
            ->sum('amount');
        $outcome = ExpenseTransaction::query()
            ->whereBetween('posted_at', [$now->startOfMonth(), $now->endOfMonth()])
            ->sum('amount');

        if ($outcome > $income) {
            $this->upsert([
                'source_key' => 'system',
                'type' => 'cashflow_negative',
                'severity' => 'critical',
                'status' => 'open',
                'title' => 'Расходы выше поступлений в месяце',
                'description' => 'За текущий месяц расходы превысили поступления.',
                'entity_type' => null,
                'entity_id' => null,
                'detected_at' => $now,
                'metadata' => ['income' => $income, 'outcome' => $outcome],
            ]);
            $count++;
        }

        return $count;
    }

    protected function upsert(array $attributes): void
    {
        Alert::query()->updateOrCreate([
            'source_key' => $attributes['source_key'],
            'type' => $attributes['type'],
            'entity_type' => $attributes['entity_type'],
            'entity_id' => $attributes['entity_id'],
            'status' => 'open',
        ], $attributes);
    }
}
