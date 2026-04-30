<?php

namespace App\Console\Commands;

use App\Models\ExpenseTransaction;
use App\Models\Project;
use App\Models\RevenueTransaction;
use App\Models\TaskTimeEntry;
use App\Services\Alerts\ProjectLimitMonitorService;
use App\Services\Notifications\TelegramNotifier;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendCompanyDailyTelegramReport extends Command
{
    protected $signature = 'company:daily-telegram
        {--period=yesterday : Report period: yesterday, today, month, 30d}
        {--force : Send even if the report was already sent for the period}
        {--dry-run : Print message instead of sending it}';

    protected $description = 'Send a short daily company summary to Telegram.';

    public function handle(TelegramNotifier $telegram, ProjectLimitMonitorService $projectLimitMonitor): int
    {
        if (! $telegram->isConfigured() && ! $this->option('dry-run')) {
            $this->warn('Telegram is not configured. Set TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID.');

            return self::SUCCESS;
        }

        $period = $this->resolvePeriod((string) $this->option('period'));
        $cacheKey = 'company-daily-telegram-report:'.$period->from->toDateString().':'.$period->to->toDateString();

        if (! $this->option('force') && ! $this->option('dry-run') && Cache::has($cacheKey)) {
            $this->info('Company daily Telegram report was already sent for this period.');

            return self::SUCCESS;
        }

        $message = $this->message($period, $projectLimitMonitor);

        if ($this->option('dry-run')) {
            $this->line($message);

            return self::SUCCESS;
        }

        try {
            $telegram->send($message);
        } catch (Throwable $throwable) {
            $this->warn($throwable->getMessage());

            return self::SUCCESS;
        }

        Cache::put($cacheKey, true, CarbonImmutable::now()->endOfDay());
        $this->info('Company daily Telegram report sent.');

        return self::SUCCESS;
    }

    protected function resolvePeriod(string $period): AnalyticsPeriod
    {
        $today = CarbonImmutable::now()->startOfDay();

        return match ($period) {
            'today' => new AnalyticsPeriod($today, $today->endOfDay(), 'today'),
            'month' => AnalyticsPeriod::preset('month'),
            '30d' => AnalyticsPeriod::preset('30d'),
            default => new AnalyticsPeriod($today->subDay()->startOfDay(), $today->subDay()->endOfDay(), 'yesterday'),
        };
    }

    protected function message(AnalyticsPeriod $period, ProjectLimitMonitorService $projectLimitMonitor): string
    {
        $finance = $this->financeSummary($period);
        $production = $this->productionSummary($period);
        $employeeHours = $this->employeeHours($period);
        $projectHours = $this->projectHours($period);
        $projectLoad = $projectLimitMonitor->projectRows();
        $overLimit = $projectLoad->filter(fn (array $row): bool => (float) $row['utilization_pct'] >= 100);
        $nearLimit = $projectLoad->filter(fn (array $row): bool => (float) $row['utilization_pct'] >= 85 && (float) $row['utilization_pct'] < 100);

        $lines = [
            '<b>Ежедневная сводка</b>',
            '',
            '<b>Финансы</b>',
            'Выручка: <b>'.$this->money($finance['revenue']).'</b>',
            'Чистыми: <b>'.$this->money($finance['net_revenue']).'</b>',
            '',
            '<b>Производство</b>',
            'Отработано: <b>'.number_format($production['hours'], 1, ',', ' ').' ч</b>',
            'Активных проектов: <b>'.number_format($production['active_projects'], 0, ',', ' ').'</b>',
            'За лимитом: <b>'.number_format($overLimit->count(), 0, ',', ' ').'</b>, на грани: <b>'.number_format($nearLimit->count(), 0, ',', ' ').'</b>',
        ];

        if ($employeeHours !== []) {
            $lines[] = '';
            $lines[] = '<b>Часы по сотрудникам</b>';

            foreach ($employeeHours as $row) {
                $lines[] = sprintf(
                    '%s: <b>%s ч</b>',
                    e((string) $row['name']),
                    number_format((float) $row['hours'], 1, ',', ' '),
                );
            }
        }

        if ($projectHours !== []) {
            $lines[] = '';
            $lines[] = '<b>Часы по проектам</b>';

            foreach ($projectHours as $row) {
                $lines[] = sprintf(
                    '%s: <b>%s ч</b>',
                    e((string) $row['name']),
                    number_format((float) $row['hours'], 1, ',', ' '),
                );
            }
        }

        $hotProjects = $projectLoad
            ->filter(fn (array $row): bool => (float) $row['utilization_pct'] >= 85)
            ->sortByDesc('utilization_pct')
            ->take(5)
            ->values();

        if ($hotProjects->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Проекты с риском</b>';

            foreach ($hotProjects as $row) {
                $lines[] = sprintf(
                    '%s: <b>%s%%</b> · %s / %s ч',
                    e((string) $row['project_name']),
                    number_format((float) $row['utilization_pct'], 1, ',', ' '),
                    number_format((float) $row['spent_hours'], 1, ',', ' '),
                    number_format((float) $row['planned_hours'], 1, ',', ' '),
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{revenue: float, net_revenue: float, expenses: float, net_cashflow: float}
     */
    protected function financeSummary(AnalyticsPeriod $period): array
    {
        $netProfitSql = Schema::hasColumn('revenue_transactions', 'net_profit_percent')
            ? 'amount * coalesce(net_profit_percent, 100) / 100.0'
            : 'amount';

        $revenue = (float) RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->sum('amount');
        $netRevenue = (float) RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->selectRaw("coalesce(sum({$netProfitSql}), 0) as total")
            ->value('total');
        $expenses = (float) ExpenseTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->sum('amount');

        return [
            'revenue' => $revenue,
            'net_revenue' => $netRevenue,
            'expenses' => $expenses,
            'net_cashflow' => $netRevenue - $expenses,
        ];
    }

    /**
     * @return array{hours: float, active_projects: int}
     */
    protected function productionSummary(AnalyticsPeriod $period): array
    {
        return [
            'hours' => round((float) TaskTimeEntry::query()
                ->whereBetween('entry_date', [$period->from->toDateString(), $period->to->toDateString()])
                ->sum(DB::raw('minutes / 60.0')), 1),
            'active_projects' => Project::query()
                ->where('status', 'active')
                ->count(),
        ];
    }

    /**
     * @return array<int, array{name: string, hours: float}>
     */
    protected function employeeHours(AnalyticsPeriod $period): array
    {
        return TaskTimeEntry::query()
            ->selectRaw("coalesce(max(employees.name), max(mapped_employees.name), max(employee_mappings.label), 'Без сотрудника') as name")
            ->selectRaw('sum(task_time_entries.minutes) / 60.0 as hours')
            ->leftJoin('employees', function ($join) {
                $join->whereRaw('employees.weeek_uuid::text = task_time_entries.employee_id::text');
            })
            ->leftJoinSub($this->employeeMappingsQuery(), 'employee_mappings', function ($join) {
                $join->whereRaw('employee_mappings.external_id = task_time_entries.employee_id::text');
            })
            ->leftJoin('employees as mapped_employees', 'mapped_employees.id', '=', 'employee_mappings.internal_id')
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupByRaw("coalesce(employees.id::text, mapped_employees.id::text, task_time_entries.employee_id::text, 'unassigned')")
            ->orderByDesc('hours')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'hours' => round((float) $row->hours, 1),
            ])
            ->filter(fn (array $row): bool => (float) $row['hours'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, hours: float}>
     */
    protected function projectHours(AnalyticsPeriod $period): array
    {
        return TaskTimeEntry::query()
            ->selectRaw("coalesce(max(projects.name), 'Без проекта') as name")
            ->selectRaw('sum(task_time_entries.minutes) / 60.0 as hours')
            ->leftJoin('tasks', 'tasks.id', '=', 'task_time_entries.task_id')
            ->leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupByRaw("coalesce(projects.id, 0)")
            ->orderByDesc('hours')
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'hours' => round((float) $row->hours, 1),
            ])
            ->filter(fn (array $row): bool => (float) $row['hours'] > 0)
            ->values()
            ->all();
    }

    private function employeeMappingsQuery()
    {
        return DB::table('source_mappings')
            ->selectRaw('external_id, max(label) as label, max(internal_id) as internal_id')
            ->where('source_key', 'weeek')
            ->where('external_type', 'user')
            ->where('internal_type', 'App\Models\Employee')
            ->groupBy('external_id');
    }

    protected function periodLabel(AnalyticsPeriod $period): string
    {
        if ($period->from->isSameDay($period->to)) {
            return $period->from->format('d.m.Y');
        }

        return $period->from->format('d.m.Y').' - '.$period->to->format('d.m.Y');
    }

    protected function money(float $value): string
    {
        return number_format($value, 0, ',', ' ').' ₽';
    }
}
