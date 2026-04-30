<?php

namespace App\Console\Commands;

use App\Models\ExpenseTransaction;
use App\Models\Project;
use App\Models\RevenueTransaction;
use App\Models\SalesOpportunity;
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
        $sales = $this->salesSummary($period);
        $production = $this->productionSummary($period);
        $projectLoad = $projectLimitMonitor->projectRows();
        $overLimit = $projectLoad->filter(fn (array $row): bool => (float) $row['utilization_pct'] >= 100);
        $nearLimit = $projectLoad->filter(fn (array $row): bool => (float) $row['utilization_pct'] >= 85 && (float) $row['utilization_pct'] < 100);

        $lines = [
            '<b>Сводка по компании</b>',
            '<b>Период:</b> '.$this->periodLabel($period),
            '',
            '<b>Финансы</b>',
            'Выручка: <b>'.$this->money($finance['revenue']).'</b>',
            'Чистыми: <b>'.$this->money($finance['net_revenue']).'</b>',
            'Расходы: <b>'.$this->money($finance['expenses']).'</b>',
            'Чистый поток: <b>'.$this->money($finance['net_cashflow']).'</b>',
            '',
            '<b>Продажи</b>',
            'Успешные сделки: <b>'.number_format($sales['won_count'], 0, ',', ' ').'</b> на <b>'.$this->money($sales['won_amount']).'</b>',
            'Новых сделок: <b>'.number_format($sales['new_count'], 0, ',', ' ').'</b>',
            '',
            '<b>Производство</b>',
            'Отработано: <b>'.number_format($production['hours'], 1, ',', ' ').' ч</b>',
            'Активных проектов: <b>'.number_format($production['active_projects'], 0, ',', ' ').'</b>',
            'За лимитом: <b>'.number_format($overLimit->count(), 0, ',', ' ').'</b>, на грани: <b>'.number_format($nearLimit->count(), 0, ',', ' ').'</b>',
        ];

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
     * @return array{won_count: int, won_amount: float, new_count: int}
     */
    protected function salesSummary(AnalyticsPeriod $period): array
    {
        $wonQuery = SalesOpportunity::query()
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->where(function ($query) {
                $query->where('sales_opportunities.status', 'won')
                    ->orWhere('stages.is_success', true)
                    ->orWhere('stages.external_id', '142');
            })
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);

        $newCount = SalesOpportunity::query()
            ->whereBetween(DB::raw('coalesce(opened_at, created_at)'), [$period->from, $period->to])
            ->count();

        return [
            'won_count' => (clone $wonQuery)->count(),
            'won_amount' => (float) (clone $wonQuery)->sum('sales_opportunities.amount'),
            'new_count' => (int) $newCount,
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
