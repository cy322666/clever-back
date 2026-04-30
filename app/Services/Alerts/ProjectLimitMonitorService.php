<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Project;
use App\Models\ProjectHealthSnapshot;
use App\Models\TaskTimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProjectLimitMonitorService
{
    public function build(): array
    {
        $rows = $this->projectRows();
        $warningRows = $rows->filter(fn (array $row) => $row['signal'] === 'warning');
        $criticalRows = $rows->filter(fn (array $row) => $row['signal'] === 'critical');
        $totalOverrunHours = round((float) $rows->sum('overrun_hours'), 1);
        $averageUtilization = $rows->isNotEmpty() ? round((float) $rows->avg('utilization_pct'), 1) : 0;

        return [
            'kpis' => [
                ['label' => 'На грани', 'value' => number_format($warningRows->count()), 'hint' => '85-99%', 'tone' => 'amber'],
                ['label' => 'За лимитом', 'value' => number_format($criticalRows->count()), 'hint' => '100%+', 'tone' => 'danger'],
                ['label' => 'Сверх плана', 'value' => number_format($totalOverrunHours, 1, ',', ' ') . ' ч', 'hint' => 'По активным проектам', 'tone' => 'rose'],
                ['label' => 'Средняя загрузка', 'value' => number_format($averageUtilization, 1, ',', ' ') . '%', 'hint' => 'По активным проектам', 'tone' => 'cyan'],
            ],
            'projects' => $rows
                ->filter(fn (array $row) => $row['signal'] !== 'ok')
                ->map(fn (array $row) => [
                'id' => $row['project_id'],
                'project_name' => $row['project_name'],
                'client_name' => $row['client_name'],
                'planned_hours' => $row['planned_hours'],
                'spent_hours' => $row['spent_hours'],
                'overrun_hours' => $row['overrun_hours'],
                'utilization_pct' => $row['utilization_pct'],
                'signal' => $row['signal'],
                'start_date' => $row['start_date'],
                'due_date' => $row['due_date'],
                'project_type' => $row['project_type'],
            ])->values()->all(),
        ];
    }

    public function refresh(): int
    {
        $rows = $this->projectRows();
        $now = CarbonImmutable::now();

        foreach ($rows as $row) {
            $project = Project::query()->find((int) $row['project_id']);

            if (! $project) {
                continue;
            }

            $project->update([
                'spent_hours' => $row['spent_hours'],
                'risk_score' => min(1, max(0.05, $row['utilization_pct'] / 100)),
                'health_status' => $row['signal'] === 'critical'
                    ? 'red'
                    : ($row['signal'] === 'warning' ? 'yellow' : 'green'),
            ]);

            ProjectHealthSnapshot::query()->updateOrCreate([
                'project_id' => $project->id,
                'snapshot_date' => $now->toDateString(),
            ], [
                'health_status' => $project->health_status,
                'risk_score' => min(1, max(0.05, $row['utilization_pct'] / 100)),
                'planned_hours' => $row['planned_hours'],
                'spent_hours' => $row['spent_hours'],
                'budget_hours' => $row['planned_hours'],
                'revenue_amount' => $project->revenue_amount,
                'payload' => [
                    'signal' => $row['signal'],
                    'utilization_pct' => $row['utilization_pct'],
                    'overrun_hours' => $row['overrun_hours'],
                    'project_type' => $row['project_type'],
                    'client_name' => $row['client_name'],
                ],
            ]);

            $this->syncAlerts($project, $row, $now);
        }

        return $rows->filter(fn (array $row) => $row['signal'] !== 'ok')->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function projectRows(): Collection
    {
        $projects = Project::query()
            ->with(['client', 'supportContract'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $timeRows = TaskTimeEntry::query()
            ->selectRaw('tasks.project_id as project_id, task_time_entries.entry_date::date as date, sum(task_time_entries.minutes) / 60.0 as hours')
            ->join('tasks', 'tasks.id', '=', 'task_time_entries.task_id')
            ->groupByRaw('tasks.project_id, task_time_entries.entry_date::date')
            ->get()
            ->groupBy('project_id');

        $warningThreshold = (float) config('dashboard.thresholds.high_utilization_threshold', 0.85) * 100;

        return $projects->map(function (Project $project) use ($timeRows, $warningThreshold) {
            $rows = $timeRows->get($project->id, collect());
            $projectStartDate = $project->start_date ? CarbonImmutable::parse($project->start_date)->startOfDay() : null;

            if ($projectStartDate !== null) {
                $rows = $rows->filter(function (object $row) use ($projectStartDate): bool {
                    return CarbonImmutable::parse((string) $row->date)->startOfDay()->greaterThanOrEqualTo($projectStartDate);
                })->values();
            }

            $plannedHours = $this->plannedHours($project);
            $spentHours = round((float) $rows->sum('hours'), 1);
            $overrunHours = max(0, $spentHours - $plannedHours);
            $utilizationPct = $plannedHours > 0 ? round(($spentHours / $plannedHours) * 100, 1) : 0;
            $signal = match (true) {
                $utilizationPct >= 100 => 'critical',
                $utilizationPct >= $warningThreshold => 'warning',
                default => 'ok',
            };

            return [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'client_name' => $project->client?->name ?? '—',
                'planned_hours' => round($plannedHours, 1),
                'spent_hours' => $spentHours,
                'overrun_hours' => round($overrunHours, 1),
                'utilization_pct' => $utilizationPct,
                'signal' => $signal,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'project_type' => $project->project_type ?? 'hourly_until_date',
            ];
        })->filter(fn (array $row) => (float) ($row['planned_hours'] ?? 0) > 0)->sortByDesc('utilization_pct')->values();
    }

    protected function plannedHours(Project $project): float
    {
        $plannedHours = (float) ($project->planned_hours ?? 0);

        if ($project->project_type === 'support_monthly') {
            $plannedHours = max($plannedHours, (float) ($project->supportContract?->monthly_hours_limit ?? 0));
        }

        return $plannedHours;
    }

    protected function syncAlerts(Project $project, array $row, CarbonImmutable $now): void
    {
        $criticalType = 'project_limit_exceeded';
        $warningType = 'project_limit_warning';

        if ($row['signal'] === 'critical') {
            Alert::query()->updateOrCreate([
                'source_key' => 'system',
                'type' => $criticalType,
                'entity_type' => Project::class,
                'entity_id' => $project->id,
                'status' => 'open',
            ], [
                'source_key' => 'system',
                'type' => $criticalType,
                'severity' => 'critical',
                'status' => 'open',
                'title' => 'Проект вышел за лимит: '.$project->name,
                'description' => 'Факт часов выше плана.',
                'entity_type' => Project::class,
                'entity_id' => $project->id,
                'score' => min(1, $row['utilization_pct'] / 100),
                'detected_at' => $now,
                'resolved_at' => null,
                'metadata' => [
                    'planned_hours' => $row['planned_hours'],
                    'spent_hours' => $row['spent_hours'],
                    'utilization_pct' => $row['utilization_pct'],
                    'project_type' => $row['project_type'],
                ],
            ]);

            $this->resolveAlert($project, $warningType, $now);
            $this->resolveAlert($project, 'project_overrun', $now);

            return;
        }

        if ($row['signal'] === 'warning') {
            Alert::query()->updateOrCreate([
                'source_key' => 'system',
                'type' => $warningType,
                'entity_type' => Project::class,
                'entity_id' => $project->id,
                'status' => 'open',
            ], [
                'source_key' => 'system',
                'type' => $warningType,
                'severity' => 'warning',
                'status' => 'open',
                'title' => 'Проект близко к лимиту: '.$project->name,
                'description' => 'Осталось меньше '.max(0, round(100 - $row['utilization_pct'], 1)).'% до предела.',
                'entity_type' => Project::class,
                'entity_id' => $project->id,
                'score' => min(1, $row['utilization_pct'] / 100),
                'detected_at' => $now,
                'resolved_at' => null,
                'metadata' => [
                    'planned_hours' => $row['planned_hours'],
                    'spent_hours' => $row['spent_hours'],
                    'utilization_pct' => $row['utilization_pct'],
                    'project_type' => $row['project_type'],
                ],
            ]);

            $this->resolveAlert($project, $criticalType, $now);
            $this->resolveAlert($project, 'project_overrun', $now);

            return;
        }

        $this->resolveAlert($project, $criticalType, $now);
        $this->resolveAlert($project, $warningType, $now);
        $this->resolveAlert($project, 'project_overrun', $now);
    }

    protected function resolveAlert(Project $project, string $type, CarbonImmutable $now): void
    {
        Alert::query()
            ->where('source_key', 'system')
            ->where('type', $type)
            ->where('entity_type', Project::class)
            ->where('entity_id', $project->id)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_at' => $now,
            ]);
    }
}
