<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\RevenueTransaction;
use App\Models\SourceConnection;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EffectiveRateAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period, array $filters = []): array
    {
        $hourRate = (float) config('dashboard.production_hour_rate', 3000);
        $rows = $this->rows($period, $hourRate);
        $filteredRows = $this->applyFilters($rows, $filters);

        return [
            'kpis' => $this->kpis($filteredRows, $hourRate),
            'rows' => $filteredRows->values()->all(),
            'all_rows' => $rows->values()->all(),
            'filter_options' => $this->filterOptions($rows),
            'period' => $period,
        ];
    }

    protected function rows(AnalyticsPeriod $period, float $hourRate): Collection
    {
        $hoursByProject = $this->hoursByProject($period);
        $revenueByProject = RevenueTransaction::query()
            ->selectRaw('project_id, sum(amount) as revenue')
            ->whereNotNull('project_id')
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->groupBy('project_id')
            ->pluck('revenue', 'project_id');

        $projectIds = collect($hoursByProject->keys())
            ->merge($revenueByProject->keys())
            ->merge(Project::query()->where('status', 'active')->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        $projects = Project::query()
            ->with(['client', 'manager', 'supportContract'])
            ->whereIn('id', $projectIds)
            ->get();

        $amoBaseUrl = $this->amoBaseUrl();

        $projectRows = $projects->map(function (Project $project) use ($hoursByProject, $revenueByProject, $hourRate, $amoBaseUrl): array {
            $hoursRow = $hoursByProject->get($project->id);
            $factHours = (float) ($hoursRow?->hours ?? 0);
            $payrollCost = (float) ($hoursRow?->payroll_cost ?? 0);
            $revenue = (float) ($revenueByProject[$project->id] ?? 0);
            $plannedHours = $this->plannedHours($project);
            $overrunHours = max(0, $factHours - $plannedHours);
            $status = $this->status($revenue, $factHours, $hourRate);

            return $this->row([
                'id' => 'project-'.$project->id,
                'client_id' => $project->client?->id,
                'client' => $project->client?->name ?: 'Без клиента',
                'client_url' => $this->clientUrl($amoBaseUrl, $project->client),
                'project_id' => $project->id,
                'project' => $project->name,
                'project_url' => \App\Filament\Pages\Production::getUrl(),
                'project_type_key' => (string) ($project->project_type ?? 'other'),
                'project_type' => $this->projectTypeLabel((string) ($project->project_type ?? '')),
                'manager_key' => $project->manager?->id ? (string) $project->manager->id : 'none',
                'manager' => $project->manager?->name ?: 'Не назначен',
                'revenue' => $revenue,
                'fact_hours' => $factHours,
                'planned_hours' => $plannedHours,
                'payroll_cost' => $payrollCost,
                'sales_hours_cost' => $factHours * $hourRate,
                'overrun_hours' => $overrunHours,
                'missed_profit' => $overrunHours * $hourRate,
                'owner_margin' => $revenue - $payrollCost,
                'status_key' => $status['key'],
                'status' => $status['label'],
                'tone' => $status['tone'],
                'rate' => $status['rate'],
                'rate_label' => $status['rate_label'],
            ]);
        });

        $clientOnlyRows = $this->clientOnlyRevenueRows($period, $hourRate, $amoBaseUrl);

        return $projectRows
            ->concat($clientOnlyRows)
            ->sortBy([
                fn (array $left, array $right): int => ($left['status_sort'] ?? 9) <=> ($right['status_sort'] ?? 9),
                fn (array $left, array $right): int => (float) ($right['overrun_hours'] ?? 0) <=> (float) ($left['overrun_hours'] ?? 0),
                fn (array $left, array $right): int => (float) ($right['revenue'] ?? 0) <=> (float) ($left['revenue'] ?? 0),
            ])
            ->values();
    }

    protected function hoursByProject(AnalyticsPeriod $period): Collection
    {
        $employeeClass = str_replace("'", "''", Employee::class);

        return DB::table('task_time_entries')
            ->selectRaw("
                tasks.project_id,
                sum(task_time_entries.minutes) / 60.0 as hours,
                sum(
                    task_time_entries.minutes / 60.0
                    * coalesce(
                        employees.salary_amount / nullif(coalesce(employees.capacity_hours_per_week, 40) * 4.333333, 0),
                        employees.hourly_cost,
                        mapped_employees.salary_amount / nullif(coalesce(mapped_employees.capacity_hours_per_week, 40) * 4.333333, 0),
                        mapped_employees.hourly_cost,
                        0
                    )
                ) as payroll_cost
            ")
            ->join('tasks', 'tasks.id', '=', 'task_time_entries.task_id')
            ->leftJoin('employees', function ($join) {
                $join->whereRaw('employees.weeek_uuid::text = task_time_entries.employee_id::text');
            })
            ->leftJoin(DB::raw("(select external_id, max(internal_id) as internal_id from source_mappings where source_key = 'weeek' and external_type = 'user' and internal_type = '{$employeeClass}' group by external_id) as employee_mappings"), function ($join) {
                $join->whereRaw('employee_mappings.external_id = task_time_entries.employee_id::text');
            })
            ->leftJoin('employees as mapped_employees', 'mapped_employees.id', '=', 'employee_mappings.internal_id')
            ->whereNotNull('tasks.project_id')
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('tasks.project_id')
            ->get()
            ->keyBy('project_id');
    }

    protected function clientOnlyRevenueRows(AnalyticsPeriod $period, float $hourRate, ?string $amoBaseUrl): Collection
    {
        return RevenueTransaction::query()
            ->selectRaw("
                revenue_transactions.client_id,
                coalesce(nullif(trim(clients.name), ''), nullif(trim(bank_statement_rows.counterparty_name), ''), 'Без клиента') as client_name,
                sum(revenue_transactions.amount) as revenue
            ")
            ->leftJoin('clients', 'clients.id', '=', 'revenue_transactions.client_id')
            ->leftJoin('bank_statement_rows', 'bank_statement_rows.id', '=', 'revenue_transactions.bank_statement_row_id')
            ->whereNull('revenue_transactions.project_id')
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->groupByRaw("revenue_transactions.client_id, coalesce(nullif(trim(clients.name), ''), nullif(trim(bank_statement_rows.counterparty_name), ''), 'Без клиента')")
            ->get()
            ->map(function ($row) use ($hourRate, $amoBaseUrl): array {
                $client = $row->client_id ? Client::query()->find((int) $row->client_id) : null;
                $status = $this->status((float) $row->revenue, 0, $hourRate);

                return $this->row([
                    'id' => 'client-only-'.($row->client_id ?: md5((string) $row->client_name)),
                    'client_id' => $row->client_id ? (int) $row->client_id : null,
                    'client' => (string) $row->client_name,
                    'client_url' => $this->clientUrl($amoBaseUrl, $client),
                    'project_id' => null,
                    'project' => 'Без проекта',
                    'project_url' => \App\Filament\Pages\Finance::getUrl(),
                    'project_type_key' => 'none',
                    'project_type' => 'Не привязано',
                    'manager_key' => 'none',
                    'manager' => 'Не назначен',
                    'revenue' => (float) $row->revenue,
                    'fact_hours' => 0,
                    'planned_hours' => 0,
                    'payroll_cost' => 0,
                    'sales_hours_cost' => 0,
                    'overrun_hours' => 0,
                    'missed_profit' => 0,
                    'owner_margin' => (float) $row->revenue,
                    'status_key' => $status['key'],
                    'status' => $status['label'],
                    'tone' => $status['tone'],
                    'rate' => $status['rate'],
                    'rate_label' => $status['rate_label'],
                ]);
            });
    }

    protected function row(array $row): array
    {
        $plannedHours = (float) ($row['planned_hours'] ?? 0);
        $factHours = (float) ($row['fact_hours'] ?? 0);
        $statusKey = (string) ($row['status_key'] ?? 'no_data');

        return [
            '__key' => (string) ($row['id'] ?? md5(json_encode($row))),
            ...$row,
            'progress_pct' => $plannedHours > 0 ? round(($factHours / $plannedHours) * 100, 1) : null,
            'status_sort' => match ($statusKey) {
                'bad' => 1,
                'warning' => 2,
                'no_hours' => 3,
                'no_data' => 4,
                'ok' => 5,
                default => 9,
            },
            'is_problem' => in_array($statusKey, ['bad', 'warning'], true) || (float) ($row['overrun_hours'] ?? 0) > 0,
        ];
    }

    protected function status(float $revenue, float $hours, float $hourRate): array
    {
        if ($hours <= 0 && $revenue > 0) {
            return ['key' => 'no_hours', 'label' => 'Нет часов', 'tone' => 'gray', 'rate' => null, 'rate_label' => 'нет часов'];
        }

        if ($hours <= 0 && $revenue <= 0) {
            return ['key' => 'no_data', 'label' => 'Нет данных', 'tone' => 'gray', 'rate' => null, 'rate_label' => 'нет данных'];
        }

        $rate = $revenue > 0 ? $revenue / $hours : 0;

        if ($rate >= $hourRate) {
            return ['key' => 'ok', 'label' => 'Нормально', 'tone' => 'success', 'rate' => $rate, 'rate_label' => number_format($rate, 0, ',', ' ').' ₽/ч'];
        }

        if ($rate >= 2000) {
            return ['key' => 'warning', 'label' => 'Внимание', 'tone' => 'warning', 'rate' => $rate, 'rate_label' => number_format($rate, 0, ',', ' ').' ₽/ч'];
        }

        return ['key' => 'bad', 'label' => 'Плохо', 'tone' => 'danger', 'rate' => $rate, 'rate_label' => number_format($rate, 0, ',', ' ').' ₽/ч'];
    }

    protected function kpis(Collection $rows, float $hourRate): array
    {
        $revenue = (float) $rows->sum('revenue');
        $hours = (float) $rows->sum('fact_hours');
        $averageRate = $hours > 0 ? $revenue / $hours : null;

        return [
            ['label' => 'Средняя фактическая ставка', 'value' => $averageRate !== null ? number_format($averageRate, 0, ',', ' ').' ₽/ч' : 'Нет часов', 'hint' => 'Выручка / факт часов', 'tone' => $averageRate === null ? 'gray' : ($averageRate >= $hourRate ? 'success' : ($averageRate >= 2000 ? 'warning' : 'danger'))],
            ['label' => 'Клиентов ниже 3000 ₽/ч', 'value' => number_format($this->uniqueClientsBelow($rows, $hourRate)), 'hint' => 'Нужно проверить тариф', 'tone' => 'warning'],
            ['label' => 'Клиентов ниже 2000 ₽/ч', 'value' => number_format($this->uniqueClientsBelow($rows, 2000)), 'hint' => 'Критично низкая ставка', 'tone' => 'danger'],
            ['label' => 'Общий перерасход часов', 'value' => number_format((float) $rows->sum('overrun_hours'), 1, ',', ' ').' ч', 'hint' => 'Факт сверх плана', 'tone' => (float) $rows->sum('overrun_hours') > 0 ? 'danger' : 'success'],
            ['label' => 'Общая упущенная прибыль', 'value' => number_format((float) $rows->sum('missed_profit'), 0, ',', ' ').' ₽', 'hint' => 'Перерасход × 3000 ₽', 'tone' => (float) $rows->sum('missed_profit') > 0 ? 'danger' : 'success'],
            ['label' => 'Выручка по выбранным клиентам', 'value' => number_format($revenue, 0, ',', ' ').' ₽', 'hint' => 'За выбранный период', 'tone' => 'emerald'],
            ['label' => 'Факт часов по выбранным клиентам', 'value' => number_format($hours, 1, ',', ' ').' ч', 'hint' => 'По учету времени', 'tone' => 'cyan'],
        ];
    }

    protected function uniqueClientsBelow(Collection $rows, float $threshold): int
    {
        return $rows
            ->filter(fn (array $row): bool => ($row['rate'] ?? null) !== null && (float) $row['rate'] < $threshold)
            ->map(fn (array $row): string => (string) ($row['client_id'] ?? $row['client']))
            ->unique()
            ->count();
    }

    protected function applyFilters(Collection $rows, array $filters): Collection
    {
        $projectType = data_get($filters, 'project_type.value');
        $manager = data_get($filters, 'manager.value');
        $status = data_get($filters, 'status.value');
        $problemOnly = data_get($filters, 'problem_only.value');

        return $rows
            ->when(filled($projectType), fn (Collection $rows) => $rows->where('project_type_key', $projectType))
            ->when(filled($manager), fn (Collection $rows) => $rows->where('manager_key', (string) $manager))
            ->when(filled($status), fn (Collection $rows) => $rows->where('status_key', $status))
            ->when($problemOnly === true || $problemOnly === '1' || $problemOnly === 1, fn (Collection $rows) => $rows->where('is_problem', true))
            ->values();
    }

    protected function filterOptions(Collection $rows): array
    {
        return [
            'project_types' => $rows->pluck('project_type', 'project_type_key')->filter()->unique()->sort()->all(),
            'managers' => $rows->pluck('manager', 'manager_key')->filter()->unique()->sort()->all(),
            'statuses' => [
                'bad' => 'Плохо',
                'warning' => 'Внимание',
                'ok' => 'Нормально',
                'no_hours' => 'Нет часов',
                'no_data' => 'Нет данных',
            ],
        ];
    }

    protected function plannedHours(Project $project): float
    {
        $plannedHours = (float) ($project->planned_hours ?? 0);

        if ($project->project_type === 'support_monthly') {
            $plannedHours = max($plannedHours, (float) ($project->supportContract?->monthly_hours_limit ?? 0));
        }

        return $plannedHours;
    }

    protected function projectTypeLabel(string $type): string
    {
        return [
            'hourly_until_date' => 'Почасовка',
            'hourly_package' => 'Пакет часов',
            'support_monthly' => 'Ежемесячная оплата',
            'one_time' => 'Разовый проект',
            'none' => 'Не привязано',
        ][$type] ?? ($type !== '' ? $type : 'Другое');
    }

    protected function amoBaseUrl(): ?string
    {
        return SourceConnection::query()
            ->where('source_key', 'amo')
            ->get()
            ->map(fn (SourceConnection $connection): ?string => $connection->settings['base_url'] ?? config('services.amo.base_url'))
            ->filter()
            ->map(fn (string $url): string => rtrim($url, '/'))
            ->first();
    }

    protected function clientUrl(?string $baseUrl, ?Client $client): ?string
    {
        if (! $baseUrl || ! $client || ! $client->external_id) {
            return null;
        }

        return $baseUrl.'/companies/detail/'.$client->external_id;
    }
}
