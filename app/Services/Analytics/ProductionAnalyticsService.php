<?php

namespace App\Services\Analytics;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductionAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $previousPeriod = $period->previousComparable();
        $threshold = config('dashboard.thresholds');
        $hourRate = (float) config('dashboard.production_hour_rate', 3000);
        $allProjects = Project::query()
            ->with('supportContract')
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        $activeProjects = $allProjects->where('status', 'active')->values();

        $projectByStage = Project::query()
            ->selectRaw("coalesce(project_type, 'Без типа') as label, count(*) as value")
            ->where('status', 'active')
            ->groupByRaw("coalesce(project_type, 'Без типа')")
            ->orderByDesc('value')
            ->get();

        $taskStatus = Task::query()
            ->selectRaw("status as label, count(*) as value")
            ->groupBy('status')
//            ->orderByDesc('value')
            ->get();

        $employees = Employee::query()
            ->selectRaw("
                weeek_uuid as worker_id,
                name,
                coalesce(capacity_hours_per_week, 40) as capacity_hours_per_week,
                salary_amount,
                hourly_cost
            ")
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->get()
            ->map(function ($row) {
                return (object) [
                    'id' => (string) $row->worker_id,
                    'name' => (string) $row->name,
                    'capacity_hours_per_week' => (float) $row->capacity_hours_per_week,
                    'salary_amount' => $row->salary_amount,
                    'hourly_cost' => $row->hourly_cost,
                    'is_active' => true,
                ];
            });

        $dayAxis = $this->dayAxis($period);
        $timeRows = $this->timeEntryRows($period);
        $projectTimeRows = $this->timeEntryRows(AnalyticsPeriod::preset('all'));
        $employees = $this->mergeTimeEntryEmployees($employees, $timeRows);
        $employeeCostMap = $employees->mapWithKeys(function (object $employee) {
            return [(string) $employee->id => $this->employeeHourlyCost($employee)];
        })->all();
        $employeeDayMatrix = $this->employeeDayMatrix($employees, $timeRows, $dayAxis, $period, $hourRate, $employeeCostMap);
        $previousDayAxis = $this->dayAxis($previousPeriod);
        $previousTimeRows = $this->timeEntryRows($previousPeriod);
        $previousEmployeeDayMatrix = $this->employeeDayMatrix($employees, $previousTimeRows, $previousDayAxis, $previousPeriod, $hourRate, $employeeCostMap);
        $totalTimeHours = (float) $timeRows->sum('hours');
        $previousTotalTimeHours = (float) $previousTimeRows->sum('hours');
        $plannedHours = Project::query()
            ->where('status', 'active')
            ->sum('planned_hours');

        $totalFactHours = $employeeDayMatrix->sum('hours');
        $totalEarned = $employeeDayMatrix->sum('earned');
        $salaryPayrollCost = Employee::query()
            ->where('is_active', true)
            ->whereNotNull('salary_amount')
            ->sum('salary_amount');
        $projectTotalTimeHours = (float) $projectTimeRows->sum('hours');
        $projectFallbackHourlyCost = $projectTotalTimeHours > 0 ? $salaryPayrollCost / $projectTotalTimeHours : 0;
        $previousFallbackHourlyCost = $previousTotalTimeHours > 0 ? $salaryPayrollCost / $previousTotalTimeHours : 0;
        $projectLoad = $this->projectLoadMatrix($allProjects, $projectTimeRows, $hourRate, $employeeCostMap, $projectFallbackHourlyCost);
        $previousProjectLoad = $this->projectLoadMatrix($allProjects, $previousTimeRows, $hourRate, $employeeCostMap, $previousFallbackHourlyCost);
        $topProjects = $projectLoad->sortByDesc('hours')->values();
        $activeProjectLoad = $projectLoad->where('project_status', 'active')->values();
        $overrunProjectsCount = $activeProjectLoad->filter(fn (array $row) => (float) ($row['hours_progress_pct'] ?? 0) > 100)->count();
        $previousActiveProjectLoad = $previousProjectLoad->where('project_status', 'active')->values();
        $previousOverrunProjectsCount = $previousActiveProjectLoad->filter(fn (array $row) => (float) ($row['hours_progress_pct'] ?? 0) > 100)->count();
        $topEmployees = $employeeDayMatrix->sortByDesc('hours')->take(8)->values();
        $loadSeries = $this->seriesByEmployee($topEmployees, $dayAxis, $topEmployees->count());
        $projectSeries = $this->seriesByProject($projectLoad, $dayAxis, min(6, $projectLoad->count()));
        $employeeHoursSummary = $this->seriesFromCollection(
            $topEmployees->map(fn (array $row) => [
                'label' => $row['employee']->name,
                'value' => $row['hours'],
            ])->values()
        ) + ['options' => ['indexAxis' => 'y']];
        $employeeEarningsSummary = $this->seriesFromCollection(
            $topEmployees->map(fn (array $row) => [
                'label' => $row['employee']->name,
                'value' => $row['earned'],
            ])->values()
        ) + ['options' => ['indexAxis' => 'y']];
        $employeeProfitSummary = $this->seriesFromCollection(
            $topEmployees->map(fn (array $row) => [
                'label' => $row['employee']->name,
                'value' => $row['owner_profit'],
            ])->values()
        ) + ['options' => ['indexAxis' => 'y']];
        $projectHoursSummary = $this->seriesFromCollection(
            $projectLoad->map(fn (array $row) => [
                'label' => $row['project_name'],
                'value' => $row['hours'],
            ])->values()
        ) + ['options' => ['indexAxis' => 'y']];
        $projectEarningsSummary = $this->seriesFromCollection(
            $projectLoad->map(fn (array $row) => [
                'label' => $row['project_name'],
                'value' => $row['earned'],
            ])->values()
        ) + ['options' => ['indexAxis' => 'y']];
        $totalPayrollCost = max($employeeDayMatrix->sum('salary_cost'), (float) $salaryPayrollCost);
        $totalOwnerProfit = $totalFactHours * $hourRate - (float) $salaryPayrollCost;
        $averageOwnerProfitPerHour = $totalFactHours > 0 ? round($totalOwnerProfit / $totalFactHours, 0) : 0;
        $previousTotalFactHours = $previousEmployeeDayMatrix->sum('hours');
        $previousTotalEarned = $previousEmployeeDayMatrix->sum('earned');
        $previousTotalPayrollCost = max($previousEmployeeDayMatrix->sum('salary_cost'), (float) $salaryPayrollCost);
        $previousTotalOwnerProfit = $previousTotalFactHours * $hourRate - (float) $salaryPayrollCost;
        $previousAverageOwnerProfitPerHour = $previousTotalFactHours > 0 ? round($previousTotalOwnerProfit / $previousTotalFactHours, 0) : 0;
        $busiestDay = collect($dayAxis['labels'])->map(function (string $label, int $index) use ($dayAxis, $employeeDayMatrix) {
            $key = $dayAxis['keys'][$index] ?? null;

            if (! $key) {
                return null;
            }

            return [
                'label' => $label,
                'hours' => $employeeDayMatrix->sum(fn (array $row) => (float) data_get($row, "daily.$key.hours", 0)),
            ];
        })->filter()->sortByDesc('hours')->first();

        $overloadedEmployees = $employeeDayMatrix->filter(fn (array $row) => ($row['utilization'] ?? 0) >= 0.95)->count();
        $underloadedEmployees = $employeeDayMatrix->filter(fn (array $row) => ($row['utilization'] ?? 0) > 0 && ($row['utilization'] ?? 0) < 0.55)->count();
        $previousOverloadedEmployees = $previousEmployeeDayMatrix->filter(fn (array $row) => ($row['utilization'] ?? 0) >= 0.95)->count();
        $previousUnderloadedEmployees = $previousEmployeeDayMatrix->filter(fn (array $row) => ($row['utilization'] ?? 0) > 0 && ($row['utilization'] ?? 0) < 0.55)->count();
        $overrunHoursTotal = (float) $activeProjectLoad->sum('overrun_hours');
        $previousOverrunHoursTotal = (float) $previousActiveProjectLoad->sum('overrun_hours');

        return [
            'kpis' => [
                ['label' => 'Стоимость часов', 'value' => number_format($totalEarned, 0, ',', ' ') . ' ₽', 'hint' => $this->moneyRateLabel($hourRate), 'tone' => 'emerald', 'comparison' => $this->compareValues($totalEarned, $previousTotalEarned)],
                ['label' => 'ФОТ', 'value' => number_format($totalPayrollCost, 0, ',', ' ') . ' ₽', 'tone' => 'amber', 'comparison' => $this->compareValues($totalPayrollCost, $previousTotalPayrollCost), 'description' => ''],
                ['label' => 'Маржа собственника', 'value' => number_format($totalOwnerProfit, 0, ',', ' ') . ' ₽', 'hint' => 'Часы × 3000 - зарплата', 'tone' => 'cyan', 'comparison' => $this->compareValues($totalOwnerProfit, $previousTotalOwnerProfit)],
                ['label' => 'Перерасход проектов', 'value' => number_format($overrunHoursTotal, 1, ',', ' ') . ' ч', 'hint' => number_format($overrunProjectsCount) . ' проектов сверх плана', 'tone' => 'danger', 'comparison' => $this->compareValues($overrunHoursTotal, $previousOverrunHoursTotal)],
            ],
            'charts' => [
                'projects_by_stage' => $this->namedSeries($projectByStage),
                'task_status' => $this->namedSeries($taskStatus),
                'load_by_employee' => $loadSeries,
                'employee_hours_summary' => $employeeHoursSummary,
                'employee_earnings_summary' => $employeeEarningsSummary,
                'employee_profit_summary' => $employeeProfitSummary,
                'load_by_project' => $projectHoursSummary,
                'earned_by_project' => $projectEarningsSummary,
                'load_by_day' => $this->seriesFromCollection(
                    collect($dayAxis['labels'])->map(function (string $label, int $index) use ($dayAxis, $employeeDayMatrix) {
                        $key = $dayAxis['keys'][$index];

                        return [
                            'label' => $label,
                            'value' => $employeeDayMatrix->sum(fn (array $row) => (float) data_get($row, "daily.$key.hours", 0)),
                        ];
                    })->values()
                ),
            ],
            'timeline' => [
                'labels' => $dayAxis['labels'],
                'dates' => $dayAxis['keys'],
            ],
            'employees' => $employeeDayMatrix,
            'project_load' => $topProjects,
            'daily_totals' => $loadSeries,
            'daily_project_chart' => $projectSeries,
            'employee_summary' => $employeeDayMatrix->sortByDesc('hours')->values(),
            'project_summary' => $topProjects,
            'busiest_day' => $busiestDay,
            'production_hour_rate' => $hourRate,
            'total_payroll_cost' => $totalPayrollCost,
            'total_owner_profit' => $totalOwnerProfit,
            'average_owner_profit_per_hour' => $averageOwnerProfitPerHour,
            'high_risk_projects' => Project::query()
                ->with('client')
                ->where('status', 'active')
                ->orderByDesc('risk_score')
                ->limit(8)
                ->get(),
            'period' => $period,
            'threshold' => $threshold,
        ];
    }

    protected function dayAxis(AnalyticsPeriod $period): array
    {
        $keys = [];
        $labels = [];

        foreach (CarbonPeriod::create($period->from->startOfDay(), '1 day', $period->to->startOfDay()) as $day) {
            $keys[] = $day->toDateString();
            $labels[] = $day->format('d.m');
        }

        return compact('keys', 'labels');
    }

    protected function timeEntryRows(AnalyticsPeriod $period): Collection
    {
        return TaskTimeEntry::query()
            ->selectRaw("
                task_time_entries.entry_date::date as date,
                coalesce(employees.weeek_uuid::text, mapped_employees.weeek_uuid::text, task_time_entries.employee_id::text) as employee_id,
                coalesce(max(employees.name), max(mapped_employees.name), max(employee_mappings.label), 'Без сотрудника') as employee_name,
                coalesce(max(employees.capacity_hours_per_week), max(mapped_employees.capacity_hours_per_week), 40) as capacity_hours_per_week,
                coalesce(max(employees.salary_amount), max(mapped_employees.salary_amount), 0) as salary_amount,
                coalesce(max(employees.hourly_cost), max(mapped_employees.hourly_cost), 0) as hourly_cost,
                coalesce(task_projects_by_id.id, task_projects_by_external.id, 0) as project_id,
                coalesce(task_projects_by_id.name, task_projects_by_external.name, 'Без проекта') as project_name,
                sum(task_time_entries.minutes) / 60.0 as hours,
                count(*) as entries
            ")
            ->leftJoin('employees', function ($join) {
                $join->whereRaw('employees.weeek_uuid::text = task_time_entries.employee_id::text');
            })
            ->leftJoinSub($this->employeeMappingsQuery(), 'employee_mappings', function ($join) {
                $join->whereRaw('employee_mappings.external_id = task_time_entries.employee_id::text');
            })
            ->leftJoin('employees as mapped_employees', 'mapped_employees.id', '=', 'employee_mappings.internal_id')
            ->leftJoin('tasks', 'tasks.id', '=', 'task_time_entries.task_id')
            ->leftJoin('projects as task_projects_by_id', 'task_projects_by_id.id', '=', 'tasks.project_id')
            ->leftJoin('projects as task_projects_by_external', function ($join) {
                $join->whereRaw('task_projects_by_external.external_id = tasks.project_id::text');
            })
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupByRaw("
                task_time_entries.entry_date::date,
                coalesce(employees.weeek_uuid::text, mapped_employees.weeek_uuid::text, task_time_entries.employee_id::text),
                coalesce(task_projects_by_id.id, task_projects_by_external.id, 0),
                coalesce(task_projects_by_id.name, task_projects_by_external.name, 'Без проекта')
            ")
            ->get();
    }

    protected function mergeTimeEntryEmployees(Collection $employees, Collection $timeRows): Collection
    {
        $existingIds = $employees
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $missing = $timeRows
            ->groupBy('employee_id')
            ->reject(fn (Collection $rows, string $employeeId): bool => in_array($employeeId, $existingIds, true))
            ->map(function (Collection $rows, string $employeeId): object {
                $first = $rows->first();

                return (object) [
                    'id' => $employeeId,
                    'name' => (string) ($first->employee_name ?? $employeeId),
                    'capacity_hours_per_week' => (float) ($first->capacity_hours_per_week ?? 40),
                    'salary_amount' => (float) ($first->salary_amount ?? 0),
                    'hourly_cost' => (float) ($first->hourly_cost ?? 0),
                    'is_active' => true,
                ];
            })
            ->values();

        return $employees
            ->concat($missing)
            ->sortBy('name')
            ->values();
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

    protected function employeeDayMatrix(Collection $employees, Collection $timeRows, array $dayAxis, AnalyticsPeriod $period, float $hourRate, array $employeeCostMap = []): Collection
    {
        $employeeLoad = $timeRows
            ->groupBy('employee_id')
            ->map(function (Collection $rows) use ($dayAxis, $hourRate, $employeeCostMap) {
                $employeeId = (string) ($rows->first()?->employee_id ?? '');
                $hourlyCost = (float) ($employeeCostMap[$employeeId] ?? 0);
                $daily = [];
                $hoursTotal = (float) $rows->sum('hours');
                $salaryTotal = (float) $rows->sum(fn (object $row) => (float) $row->hours * $hourlyCost);
                $salaryAmount = $hourlyCost > 0 && $hoursTotal > 0 ? round($salaryTotal, 0) : 0;
                $hourCostByPeriod = $hoursTotal > 0 && $salaryAmount > 0 ? round($salaryAmount / $hoursTotal, 2) : 0;

                foreach ($dayAxis['keys'] as $dayKey) {
                    $dayRows = $rows->where('date', $dayKey);
                    $daily[$dayKey] = [
                        'hours' => round($dayRows->sum('hours'), 2),
                        'earned' => round($dayRows->sum('hours') * $hourRate, 0),
                        'salary_cost' => round($dayRows->sum('hours') * $hourlyCost, 0),
                        'owner_profit' => round($dayRows->sum('hours') * $hourRate, 0),
                        'entries' => (int) $dayRows->sum('entries'),
                    ];
                }

                return [
                    'hours' => round($hoursTotal, 1),
                    'earned' => round($rows->sum('hours') * $hourRate, 0),
                    'salary_cost' => round($hoursTotal * $hourlyCost, 0),
                    'salary_amount' => $salaryAmount,
                    'hour_cost_by_period' => $hourCostByPeriod,
                    'owner_profit' => round($hoursTotal * $hourRate - $salaryAmount, 0),
                    'entries' => (int) $rows->sum('entries'),
                    'daily' => $daily,
                    'projects' => $rows
                        ->groupBy('project_name')
                        ->map(fn (Collection $projectRows) => round($projectRows->sum('hours'), 1))
                        ->sortDesc()
                        ->take(4)
                        ->all(),
                ];
            });

        return $employees->map(function ($employee) use ($employeeLoad, $period, $hourRate) {
            $row = $employeeLoad->get((string) $employee->id, [
                'hours' => 0,
                'earned' => 0,
                'salary_cost' => 0,
                'owner_profit' => 0,
                'entries' => 0,
                'daily' => [],
                'projects' => [],
            ]);

            $expected = $employee->capacity_hours_per_week * $this->weeksInPeriod($period);
            $utilization = $expected > 0 ? round(($row['hours'] / $expected) * 100, 1) : 0;
            $hourlyCost = $this->employeeHourlyCost($employee);
            $salaryAmount = (float) ($employee->salary_amount ?? 0);
            $hourCostByPeriod = $row['hours'] > 0 && $salaryAmount > 0 ? round($salaryAmount / $row['hours'], 2) : 0;
            $ownerProfitPerHour = $hourRate - $hourlyCost;

            return [
                'employee' => $employee,
                'hours' => (float) $row['hours'],
                'earned' => (float) $row['earned'],
                'salary_cost' => (float) $row['salary_cost'],
                'salary_amount' => $salaryAmount,
                'hour_cost_by_period' => $hourCostByPeriod,
                'owner_profit' => round(((float) $row['hours'] * $hourRate) - $salaryAmount, 0),
                'entries' => (int) $row['entries'],
                'hour_rate' => $hourRate,
                'hourly_cost' => $hourlyCost,
                'owner_profit_per_hour' => $ownerProfitPerHour,
                'expected' => round($expected, 1),
                'utilization' => $expected > 0 ? round($row['hours'] / $expected, 2) : 0,
                'utilization_pct' => $utilization,
                'daily' => $row['daily'],
                'projects' => $row['projects'],
                'peak_day' => collect($row['daily'])->sortByDesc('hours')->keys()->first(),
            ];
        });
    }

    protected function projectLoadMatrix(Collection $projects, Collection $timeRows, float $hourRate, array $employeeCostMap = [], float $fallbackHourlyCost = 0): Collection
    {
        $rowsByProjectId = $timeRows
            ->where('project_id', '>', 0)
            ->groupBy('project_id');

        return $projects->map(function (Project $project) use ($rowsByProjectId, $hourRate, $employeeCostMap, $fallbackHourlyCost) {
            $rows = $rowsByProjectId->get($project->id, collect());
            $projectStartDate = $project->start_date ? CarbonImmutable::parse($project->start_date)->startOfDay() : null;
            $projectDueDate = $project->due_date?->toDateString();

            if ($projectStartDate !== null) {
                $rows = $rows->filter(function (object $row) use ($projectStartDate): bool {
                    $rowDate = CarbonImmutable::parse((string) $row->date)->startOfDay();

                    return $rowDate->greaterThanOrEqualTo($projectStartDate);
                })->values();
            }

            $plannedHours = (float) ($project->planned_hours ?? 0);

            if ($project->project_type === 'support_monthly') {
                $plannedHours = max($plannedHours, (float) ($project->supportContract?->monthly_hours_limit ?? 0));
            }

            $salaryCost = $rows->sum(function (TaskTimeEntry $row) use ($employeeCostMap, $fallbackHourlyCost): float {
                $employeeId = (string) ($row->employee_id ?? '');
                $hourlyCost = (float) ($employeeCostMap[$employeeId] ?? 0);

                if ($hourlyCost <= 0 && $fallbackHourlyCost > 0) {
                    $hourlyCost = $fallbackHourlyCost;
                }

                return (float) $row->hours * $hourlyCost;
            });
            $earned = $rows->sum(fn (TaskTimeEntry $row) => (float) $row->hours * $hourRate);
            $ownerProfit = $earned - $salaryCost;
            $totalHours = (float) $rows->sum('hours');
            $overrunHours = max(0, $totalHours - $plannedHours);
            $missedProfit = $overrunHours * $hourRate;

            return [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_type' => $project->project_type ?? 'hourly_until_date',
                'project_status' => $project->status,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $projectDueDate,
                'hours' => round($totalHours, 1),
                'planned_hours_total' => round($plannedHours, 1),
                'hours_progress_pct' => $plannedHours > 0 ? round(($totalHours / $plannedHours) * 100, 1) : null,
                'hours_progress' => $plannedHours > 0
                    ? number_format($totalHours, 1, ',', ' ') . ' / ' . number_format($plannedHours, 1, ',', ' ') . ' ч'
                    : number_format($totalHours, 1, ',', ' ') . ' ч',
                'overrun_hours' => round($overrunHours, 1),
                'missed_profit' => round($missedProfit, 0),
                'earned' => round($earned, 0),
                'salary_cost' => round($salaryCost, 0),
                'owner_profit' => round($ownerProfit, 0),
                'entries' => (int) $rows->sum('entries'),
                'employees' => $rows->pluck('employee_id')->filter()->unique()->count(),
                'owner_profit_per_hour' => round($totalHours > 0 ? $ownerProfit / $totalHours : 0, 0),
                'daily' => $rows
                    ->groupBy('date')
                    ->map(fn (Collection $dayRows) => round($dayRows->sum('hours'), 2))
                    ->all(),
            ];
        })->values();
    }

    protected function employeeSalaryNormHours(object $employee): float
    {
        return max(1, (float) $employee->capacity_hours_per_week * 4.333333);
    }

    protected function employeeHourlyCost(object $employee): float
    {
        if ((float) ($employee->salary_amount ?? 0) > 0) {
            return round(((float) $employee->salary_amount) / $this->employeeSalaryNormHours($employee), 2);
        }

        return round((float) ($employee->hourly_cost ?? 0), 2);
    }

    protected function employeeMonthlySalary(object $employee): float
    {
        if ((float) ($employee->salary_amount ?? 0) > 0) {
            return round((float) $employee->salary_amount, 0);
        }

        return round($this->employeeHourlyCost($employee) * $this->employeeSalaryNormHours($employee), 0);
    }

    protected function seriesFromCollection(Collection $rows): array
    {
        return [
            'labels' => $rows->pluck('label')->values()->all(),
            'values' => $rows->pluck('value')->map(fn ($value) => (float) $value)->values()->all(),
        ];
    }

    protected function moneyRateLabel(float $hourRate): string
    {
        return number_format($hourRate, 0, ',', ' ').' ₽ / час';
    }

    protected function seriesByEmployee(Collection $rows, array $dayAxis, int $limit = 8): array
    {
        $selected = $rows->sortByDesc('hours')->take($limit)->values();

        return [
            'labels' => $dayAxis['labels'],
            'datasets' => $selected->map(function (array $row, int $index) use ($dayAxis) {
                $data = [];

                foreach ($dayAxis['keys'] as $dayKey) {
                    $data[] = (float) data_get($row, "daily.$dayKey.hours", 0);
                }

                return [
                    'label' => $row['employee']->name,
                    'data' => $data,
                    'backgroundColor' => $this->employeeColor($index),
                    'borderColor' => $this->employeeColor($index),
                ];
            })->values()->all(),
            'options' => ['stacked' => true],
        ];
    }

    protected function seriesByProject(Collection $rows, array $dayAxis, int $limit = 6): array
    {
        $selected = $rows->sortByDesc('hours')->take($limit)->values();

        return [
            'labels' => $dayAxis['labels'],
            'datasets' => $selected->map(function (array $row, int $index) use ($dayAxis) {
                $data = [];

                foreach ($dayAxis['keys'] as $dayKey) {
                    $data[] = (float) ($row['daily'][$dayKey] ?? 0);
                }

                return [
                    'label' => $row['project_name'],
                    'data' => $data,
                    'backgroundColor' => $this->projectColor($index),
                    'borderColor' => $this->projectColor($index),
                ];
            })->values()->all(),
            'options' => ['stacked' => true],
        ];
    }

    protected function employeeColor(int $index): string
    {
        $colors = ['#6ba7ff', '#7fd4a3', '#d8b26d', '#e67c89', '#9986ff', '#68c7bf', '#c9a85b', '#89b9ff'];

        return $colors[$index % count($colors)];
    }

    protected function projectColor(int $index): string
    {
        $colors = ['#8bd4ff', '#a3e072', '#f1b36b', '#cf83ff', '#6dd0c2', '#f08da0'];

        return $colors[$index % count($colors)];
    }
}
