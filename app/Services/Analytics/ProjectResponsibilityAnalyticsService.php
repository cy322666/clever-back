<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Models\Task;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ProjectResponsibilityAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period, array $production): array
    {
        $projectLoad = collect($production['project_summary'] ?? [])
            ->keyBy(fn (array $row): int => (int) ($row['project_id'] ?? 0));
        $overdueByProject = $this->overdueTasksByProject();

        $projects = Project::query()
            ->with(['responsible', 'client'])
            ->where('status', 'active')
            ->whereNotNull('responsible_employee_id')
            ->orderBy('name')
            ->get();

        return $projects
            ->groupBy('responsible_employee_id')
            ->map(function (Collection $projects, int|string $employeeId) use ($projectLoad, $overdueByProject): array {
                $items = $projects->map(function (Project $project) use ($projectLoad, $overdueByProject): array {
                    $load = $projectLoad->get((int) $project->id, []);
                    $progress = $load['hours_progress_pct'] ?? null;
                    $overrunHours = (float) ($load['overrun_hours'] ?? 0);
                    $overdueTasks = (int) ($overdueByProject[$project->id] ?? 0);
                    $lastActivity = $project->last_activity_at ?? $project->updated_at;
                    $isIdle = $lastActivity
                        ? CarbonImmutable::parse($lastActivity)->lessThanOrEqualTo(now()->subDays(7))
                        : false;
                    $isRed = ((float) ($progress ?? 0) >= 90)
                        || $overrunHours > 0
                        || $overdueTasks > 0
                        || $isIdle;

                    return [
                        'id' => $project->id,
                        'name' => $project->name,
                        'client' => $project->client?->name,
                        'progress_pct' => $progress,
                        'overrun_hours' => $overrunHours,
                        'overdue_tasks' => $overdueTasks,
                        'is_idle' => $isIdle,
                        'is_red' => $isRed,
                    ];
                })->values();

                return [
                    'employee_id' => (int) $employeeId,
                    'responsible_projects_count' => $items->count(),
                    'red_projects_count' => $items->where('is_red', true)->count(),
                    'projects' => $items->all(),
                    'project_names' => $items->pluck('name')->values()->all(),
                    'red_project_names' => $items->where('is_red', true)->pluck('name')->values()->all(),
                ];
            })
            ->all();
    }

    protected function overdueTasksByProject(): Collection
    {
        return Task::query()
            ->selectRaw('project_id, count(*) as overdue_count')
            ->whereNotNull('project_id')
            ->where('due_at', '<', now())
            ->whereNull('completed_at')
            ->whereNotIn('status', ['done', 'completed', 'closed'])
            ->groupBy('project_id')
            ->pluck('overdue_count', 'project_id');
    }
}
