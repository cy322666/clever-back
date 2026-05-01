<?php

namespace App\Services\Analytics;

use App\Filament\Pages\Finance;
use App\Filament\Pages\Invoices;
use App\Filament\Pages\Production;
use App\Filament\Pages\Sales;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RevenueTransaction;
use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OwnerPulseAnalyticsService extends AnalyticsService
{
    /** @var array<string, array<string, mixed>> */
    protected static array $cache = [];

    public function build(AnalyticsPeriod $period): array
    {
        $cacheKey = implode(':', [$period->key, $period->from->toDateString(), $period->to->toDateString()]);

        if (isset(static::$cache[$cacheKey])) {
            return static::$cache[$cacheKey];
        }

        $hourRate = (float) config('dashboard.production_hour_rate', 3000);
        $finance = $this->financeMetrics($period);
        $sales = $this->salesMetrics($period);
        $production = app(ProductionAnalyticsService::class)->build($period);
        $productionMetrics = $this->productionMetrics($production, $hourRate);
        $owner = $this->ownerMetrics($production, $period, $hourRate);
        $attention = $this->attentionRows($period, $production, $owner, $hourRate);
        $riskProjects = $this->riskProjectRows($production, $hourRate);
        $teamLoad = $this->teamLoadRows($period, $production, $hourRate, $owner);
        $sources = $this->salesSourceRows($period);

        return static::$cache[$cacheKey] = [
            'money_cards' => $finance['cards'],
            'sales_cards' => $sales['cards'],
            'production_cards' => $productionMetrics['cards'],
            'owner_hours_card' => $owner['card'],
            'attention' => $attention,
            'sales_sources' => $sources,
            'risk_projects' => $riskProjects,
            'team_load' => $teamLoad,
            'period' => $period,
        ];
    }

    protected function financeMetrics(AnalyticsPeriod $period): array
    {
        $revenues = RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to]);

        $revenue = (float) (clone $revenues)->sum('amount');
        $netBeforePayroll = (float) (clone $revenues)
            ->selectRaw('coalesce(sum('.$this->netProfitAmountSql().'), 0) as total')
            ->value('total');
        $payroll = $this->payrollCostForPeriod($period);
        $netProfit = $netBeforePayroll - $payroll;
        $margin = $revenue > 0 ? ($netProfit / $revenue) * 100 : null;
        $mrr = $this->supportMrr();
        $expectedPayments = $this->expectedPaymentsNext30Days();
        $receivables = $this->openInvoiceAmount();
        $openInvoicesCount = $this->openInvoices()->count();

        return [
            'cards' => [
                ['label' => 'Выручка за период', 'value' => $this->money($revenue), 'hint' => 'Поступления', 'tone' => $revenue > 0 ? 'emerald' : 'gray', 'description' => $revenue > 0 ? 'Все оплаты за период' : 'Нет данных за выбранный период'],
                ['label' => 'Чистая прибыль', 'value' => $this->money($netProfit), 'hint' => 'Прибыль минус зарплаты', 'tone' => $netProfit < 0 ? 'danger' : ($netProfit > 0 ? 'emerald' : 'gray'), 'description' => $revenue > 0 ? 'С учетом фонда оплаты труда' : 'Нет данных за выбранный период'],
                ['label' => 'Маржинальность', 'value' => $margin === null ? 'Нет данных' : $this->percent($margin), 'hint' => 'Чистая прибыль / выручка', 'tone' => $margin === null ? 'gray' : ($margin < 20 ? 'warning' : 'success'), 'description' => $margin === null ? 'Нет данных за выбранный период' : 'После зарплат'],
                ['label' => 'Регулярная выручка сопровождения', 'value' => $this->money($mrr), 'hint' => 'Ежемесячные оплаты', 'tone' => $mrr > 0 ? 'cyan' : 'gray', 'description' => $mrr > 0 ? 'Открытые сделки/проекты сопровождения' : 'Нет данных'],
                ['label' => 'Ожидаемые оплаты 30 дней', 'value' => $this->money($expectedPayments), 'hint' => 'Следующие оплаты клиентов', 'tone' => $expectedPayments > 0 ? 'amber' : 'gray', 'description' => $expectedPayments > 0 ? 'Ближайшие оплаты' : 'Нет данных'],
                ['label' => 'Счета к оплате', 'value' => $this->money($receivables), 'hint' => $openInvoicesCount.' счетов', 'tone' => $receivables > 0 ? 'amber' : 'gray', 'description' => $receivables > 0 ? 'Дебиторка' : 'Нет неоплаченных счетов'],
            ],
            'revenue' => $revenue,
            'net_profit' => $netProfit,
            'margin' => $margin,
            'mrr' => $mrr,
            'expected_payments' => $expectedPayments,
            'receivables' => $receivables,
        ];
    }

    protected function salesMetrics(AnalyticsPeriod $period): array
    {
        $leadDate = DB::raw('coalesce(lead_created_at, created_at)');
        $newLeads = SalesLead::query()
            ->whereBetween($leadDate, [$period->from, $period->to])
            ->count();

        $wonDeals = $this->wonDealsQuery($period);
        $wonCount = (clone $wonDeals)->count();
        $wonAmount = (float) (clone $wonDeals)->sum('sales_opportunities.amount');
        $conversion = $newLeads > 0 ? ($wonCount / $newLeads) * 100 : null;
        $averageCheck = $wonCount > 0 ? $wonAmount / $wonCount : 0;
        $openDeals = SalesOpportunity::query()->where('status', 'open');
        $pipeline = (float) (clone $openDeals)->sum('amount');
        $forecast = (float) (clone $openDeals)->sum(DB::raw('amount * coalesce(probability, 0) / 100.0'));
        $idleDeals = SalesOpportunity::query()
            ->where('status', 'open')
            ->where(function ($query) {
                $query
                    ->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<=', now()->subDays(5));
            })
            ->count();

        return [
            'cards' => [
                ['label' => 'Новые лиды', 'value' => (string) $newLeads, 'hint' => 'За период', 'tone' => $newLeads > 0 ? 'brand' : 'gray', 'description' => $newLeads > 0 ? 'Входящий поток' : 'Нет данных за выбранный период'],
                ['label' => 'Выигранные сделки', 'value' => (string) $wonCount, 'hint' => $this->money($wonAmount), 'tone' => $wonCount > 0 ? 'success' : 'gray', 'description' => $wonCount > 0 ? 'Оплаченные/успешные' : 'Нет данных за выбранный период'],
                ['label' => 'Конверсия в оплату', 'value' => $conversion === null ? 'Нет данных' : $this->percent($conversion), 'hint' => 'Выигранные сделки / лиды', 'tone' => $conversion === null ? 'gray' : ($conversion < 10 ? 'warning' : 'success'), 'description' => $conversion === null ? 'Недостаточно данных' : 'По выбранному периоду'],
                ['label' => 'Средний чек', 'value' => $this->money($averageCheck), 'hint' => 'По выигранным', 'tone' => $averageCheck > 0 ? 'cyan' : 'gray', 'description' => $averageCheck > 0 ? 'Средняя сумма сделки' : 'Нет данных'],
                ['label' => 'Воронка открытых сделок', 'value' => $this->money($pipeline), 'hint' => 'Сделки в работе', 'tone' => $pipeline > 0 ? 'amber' : 'gray', 'description' => $pipeline > 0 ? 'Будущие продажи' : 'Нет открытых сделок'],
                ['label' => 'Прогноз продаж', 'value' => $this->money($forecast), 'hint' => 'С учетом вероятности', 'tone' => $forecast > 0 ? 'emerald' : 'gray', 'description' => $forecast > 0 ? 'Прогноз с учетом вероятности' : 'Нет данных'],
                ['label' => 'Сделки без движения', 'value' => (string) $idleDeals, 'hint' => '5+ дней', 'tone' => $idleDeals > 0 ? 'danger' : 'success', 'description' => $idleDeals > 0 ? 'Нужен контакт/задача' : 'Все ок'],
            ],
            'new_leads' => $newLeads,
            'won_count' => $wonCount,
            'won_amount' => $wonAmount,
            'pipeline' => $pipeline,
            'forecast' => $forecast,
            'idle_deals' => $idleDeals,
        ];
    }

    protected function productionMetrics(array $production, float $hourRate): array
    {
        $projects = collect($production['project_summary'] ?? []);
        $activeProjects = $projects->where('project_status', 'active')->values();
        $employees = collect($production['employee_summary'] ?? []);
        $factHours = (float) $employees->sum('hours');
        $hoursCost = $factHours * $hourRate;
        $overrunHours = (float) $activeProjects->sum('overrun_hours');
        $overPlanCount = $activeProjects->filter(fn (array $row): bool => (float) ($row['hours_progress_pct'] ?? 0) >= 100)->count();
        $avgUtilization = $employees->count() > 0 ? (float) $employees->avg('utilization_pct') : null;
        $activeCount = Project::query()->where('status', 'active')->count();
        $over80 = $activeProjects->filter(fn (array $row): bool => (float) ($row['hours_progress_pct'] ?? 0) >= 80)->count();
        $over100 = $activeProjects->filter(fn (array $row): bool => (float) ($row['hours_progress_pct'] ?? 0) >= 100)->count();

        return [
            'cards' => [
                ['label' => 'Отработано часов', 'value' => $this->hours($factHours), 'hint' => 'За период', 'tone' => $factHours > 0 ? 'cyan' : 'gray', 'description' => $factHours > 0 ? 'Командный учет времени' : 'Нет данных за выбранный период'],
                ['label' => 'Стоимость отработанных часов', 'value' => $this->money($hoursCost), 'hint' => $this->money($hourRate).'/ч', 'tone' => $hoursCost > 0 ? 'emerald' : 'gray', 'description' => $hoursCost > 0 ? 'Факт × ставка' : 'Нет данных'],
                ['label' => 'Перерасход по проектам', 'value' => $this->hours($overrunHours), 'hint' => 'По активным проектам', 'tone' => $overrunHours > 0 ? 'danger' : 'success', 'description' => $overrunHours > 0 ? 'Нужны действия' : 'В рамках плана'],
                ['label' => 'Проектов сверх плана', 'value' => (string) $overPlanCount, 'hint' => '100%+ выработки', 'tone' => $overPlanCount > 0 ? 'danger' : 'success', 'description' => $overPlanCount > 0 ? 'Проверь таблицу риска' : 'Нет перерасхода'],
                ['label' => 'Средняя загрузка команды', 'value' => $avgUtilization === null ? 'Нет данных' : $this->percent($avgUtilization), 'hint' => 'От доступных часов', 'tone' => $avgUtilization === null ? 'gray' : ($avgUtilization >= 95 ? 'danger' : ($avgUtilization >= 85 ? 'warning' : 'success')), 'description' => $avgUtilization === null ? 'Нет сотрудников/часов' : 'По выбранному периоду'],
                ['label' => 'Активные проекты', 'value' => (string) $activeCount, 'hint' => 'В работе', 'tone' => $activeCount > 0 ? 'brand' : 'gray', 'description' => $activeCount > 0 ? 'Текущий портфель' : 'Нет активных проектов'],
                ['label' => 'Проекты 80%+', 'value' => (string) $over80, 'hint' => 'Риск перерасхода', 'tone' => $over80 > 0 ? 'warning' : 'success', 'description' => $over80 > 0 ? 'Следить за планом' : 'Нет рисков'],
                ['label' => 'Проекты 100%+', 'value' => (string) $over100, 'hint' => 'Сверх плана', 'tone' => $over100 > 0 ? 'danger' : 'success', 'description' => $over100 > 0 ? 'Нужен пересмотр' : 'Нет перерасхода'],
            ],
            'fact_hours' => $factHours,
            'hours_cost' => $hoursCost,
            'overrun_hours' => $overrunHours,
            'over_plan_count' => $overPlanCount,
            'avg_utilization' => $avgUtilization,
        ];
    }

    protected function ownerMetrics(array $production, AnalyticsPeriod $period, float $hourRate): array
    {
        $owner = $this->ownerEmployee();
        $employees = collect($production['employee_summary'] ?? []);
        $totalHours = (float) $employees->sum('hours');
        $ownerRow = $owner
            ? $employees->first(fn (array $row): bool => (string) data_get($row, 'employee.id') === (string) $owner->weeek_uuid)
            : null;
        $hours = (float) ($ownerRow['hours'] ?? 0);
        $share = $totalHours > 0 ? ($hours / $totalHours) * 100 : 0;
        $cost = $hours * $hourRate;
        $status = match (true) {
            $hours >= 60 => ['label' => 'Перегруз', 'tone' => 'danger', 'hint' => '60+ ч/мес'],
            $hours >= 30 => ['label' => 'Внимание', 'tone' => 'warning', 'hint' => '30-60 ч/мес'],
            default => ['label' => 'Нормально', 'tone' => 'success', 'hint' => 'до 30 ч/мес'],
        };

        if (! $owner) {
            $status = ['label' => 'Не настроен', 'tone' => 'gray', 'hint' => 'Укажите основателя в настройках окружения'];
        }

        return [
            'employee' => $owner,
            'hours' => $hours,
            'share' => $share,
            'cost' => $cost,
            'status' => $status['label'],
            'tone' => $status['tone'],
            'card' => [
                [
                    'label' => 'Мои часы в производстве',
                    'value' => $owner ? $this->hours($hours) : 'Не настроен',
                    'hint' => $owner ? $status['label'].' · '.$this->percent($share).' команды · '.$this->money($cost) : $status['hint'],
                    'tone' => $status['tone'],
                    'description' => $owner ? 'Цель: меньше рутины, больше управления и сложных кейсов' : 'Укажи OWNER_USER_ID или OWNER_EMAIL',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function attentionRows(AnalyticsPeriod $period, array $production, array $owner, float $hourRate): array
    {
        $rows = collect();
        $projectRows = collect($production['project_summary'] ?? [])->where('project_status', 'active');
        $projectsById = Project::query()->with(['client', 'manager'])->where('status', 'active')->get()->keyBy('id');

        foreach ($projectRows as $row) {
            $project = $projectsById->get((int) ($row['project_id'] ?? 0));
            $progress = (float) ($row['hours_progress_pct'] ?? 0);

            if ($progress >= 100) {
                $rows->push($this->attentionRow('project', $row['project_name'] ?? 'Проект', 'Проект сверх плана', $this->percent($progress), 'Пересогласовать бюджет/лимит часов', $project?->manager?->name, 'high', Production::getUrl()));
            } elseif ($progress >= 80) {
                $rows->push($this->attentionRow('project', $row['project_name'] ?? 'Проект', 'Риск перерасхода', $this->percent($progress), 'Проверить остаток плана и приоритизировать задачи', $project?->manager?->name, 'medium', Production::getUrl()));
            }
        }

        foreach ($projectsById as $project) {
            if (! $project->manager_employee_id) {
                $rows->push($this->attentionRow('project', $project->name, 'Не назначен ответственный', 'Нет менеджера', 'Назначить ответственного', null, 'medium', Production::getUrl()));
            }

            $lastActivity = $project->last_activity_at ?? $project->updated_at;
            if ($lastActivity && CarbonImmutable::parse($lastActivity)->lessThanOrEqualTo(now()->subDays(7))) {
                $rows->push($this->attentionRow('project', $project->name, 'Проект без движения', CarbonImmutable::parse($lastActivity)->format('d.m.Y'), 'Проверить статус и назначить следующее действие', $project->manager?->name, 'medium', Production::getUrl()));
            }
        }

        $overdueByProject = Task::query()
            ->selectRaw('project_id, count(*) as overdue_count')
            ->whereNotNull('project_id')
            ->where('due_at', '<', now())
            ->whereNull('completed_at')
            ->whereNotIn('status', ['done', 'completed', 'closed'])
            ->groupBy('project_id')
            ->get();

        foreach ($overdueByProject as $overdue) {
            $project = $projectsById->get((int) $overdue->project_id);
            $rows->push($this->attentionRow('project', $project?->name ?: 'Проект #'.$overdue->project_id, 'Есть просрочки', $overdue->overdue_count.' задач', 'Разобрать просроченные задачи', $project?->manager?->name, 'high', Production::getUrl()));
        }

        foreach (app(EffectiveRateAnalyticsService::class)->build($period)['rows'] ?? [] as $rateRow) {
            $rate = $rateRow['rate'] ?? null;

            if ($rate !== null && (float) $rate < 2000) {
                $rows->push($this->attentionRow('client', (string) $rateRow['client'], 'Низкая фактическая ставка', (string) $rateRow['rate_label'], 'Поднять тариф, выставить доп. счёт или сократить объём', (string) ($rateRow['manager'] ?? 'Не назначен'), 'high', Finance::getUrl()));
            } elseif ($rate !== null && (float) $rate < $hourRate) {
                $rows->push($this->attentionRow('client', (string) $rateRow['client'], 'Низкая фактическая ставка', (string) $rateRow['rate_label'], 'Проверить экономику клиента и условия оплаты', (string) ($rateRow['manager'] ?? 'Не назначен'), 'medium', Finance::getUrl()));
            }

            if ((float) ($rateRow['overrun_hours'] ?? 0) > 0) {
                $rows->push($this->attentionRow('project', (string) $rateRow['project'], 'Перерасход часов', $this->hours((float) $rateRow['overrun_hours']), 'Выставить доп. счёт или пересогласовать лимит работ', (string) ($rateRow['manager'] ?? 'Не назначен'), (float) ($rateRow['progress_pct'] ?? 0) >= 100 ? 'high' : 'medium', Production::getUrl()));
            }
        }

        Buyer::query()
            ->with('client')
            ->where(function ($query) {
                $query->where('ltv', '>=', (float) config('dashboard.thresholds.client_growth_revenue_threshold', 350000))
                    ->whereNull('next_date');
            })
            ->limit(20)
            ->get()
            ->each(function (Buyer $buyer) use ($rows): void {
                $rows->push($this->attentionRow('client', $buyer->client?->name ?: $buyer->name ?: 'Клиент #'.$buyer->id, 'Нет плана развития', $this->money((float) $buyer->ltv), 'Запланировать следующий шаг по клиенту', null, 'medium', Sales::getUrl()));
            });

        Buyer::query()
            ->with('client')
            ->whereBetween('next_date', [now(), now()->addDays(7)])
            ->get()
            ->each(function (Buyer $buyer) use ($rows): void {
                $rows->push($this->attentionRow('client', $buyer->client?->name ?: $buyer->name ?: 'Клиент #'.$buyer->id, 'Проверить продление/оплату', optional($buyer->next_date)->format('d.m.Y'), 'Связаться до даты оплаты', null, 'medium', Sales::getUrl()));
            });

        $this->openInvoices()->limit(30)->get()->each(function (Invoice $invoice) use ($rows): void {
            $invoiceDate = $invoice->invoice_date ?? $invoice->created_at;
            $isOverdue = $invoiceDate && CarbonImmutable::parse($invoiceDate)->lessThanOrEqualTo(now()->subDays(7));
            $rows->push($this->attentionRow('invoice', $invoice->customer_name ?: $invoice->name, $isOverdue ? 'Счёт просрочен' : 'Выставленный счёт не оплачен', $this->money((float) $invoice->amount), 'Дожать оплату/уточнить статус', null, $isOverdue ? 'high' : 'medium', Invoices::getUrl()));
        });

        SalesOpportunity::query()
            ->with(['client', 'owner'])
            ->where('status', 'open')
            ->where(function ($query) {
                $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<=', now()->subDays(5));
            })
            ->limit(30)
            ->get()
            ->each(function (SalesOpportunity $deal) use ($rows): void {
                $rows->push($this->attentionRow('deal', $deal->name ?: 'Сделка #'.$deal->id, 'Сделка без движения', optional($deal->last_activity_at)->format('d.m.Y') ?: 'Нет активности', 'Поставить задачу и связаться с клиентом', $deal->owner?->name, 'medium', Sales::getUrl()));
            });

        SalesOpportunity::query()
            ->with(['client', 'owner'])
            ->where('status', 'open')
            ->whereRaw("(sales_opportunities.metadata #>> '{amo_lead,closest_task_at}') is null")
            ->limit(30)
            ->get()
            ->each(function (SalesOpportunity $deal) use ($rows): void {
                $rows->push($this->attentionRow('deal', $deal->name ?: 'Сделка #'.$deal->id, 'Сделка без задачи', $this->money((float) $deal->amount), 'Поставить следующую задачу в amoCRM', $deal->owner?->name, 'medium', Sales::getUrl()));
            });

        SalesOpportunity::query()
            ->with(['client', 'owner', 'stage'])
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->select('sales_opportunities.*')
            ->where('sales_opportunities.status', 'open')
            ->where(function ($query) {
                $query
                    ->whereRaw("lower(coalesce(stages.name, '')) like '%кп%'")
                    ->orWhereRaw("lower(coalesce(stages.name, '')) like '%предлож%'");
            })
            ->where(function ($query) {
                $query->whereNull('sales_opportunities.last_activity_at')->orWhere('sales_opportunities.last_activity_at', '<=', now()->subDays(5));
            })
            ->limit(20)
            ->get()
            ->each(function (SalesOpportunity $deal) use ($rows): void {
                $rows->push($this->attentionRow('deal', $deal->name ?: 'Сделка #'.$deal->id, 'КП отправлено, нет ответа', optional($deal->last_activity_at)->format('d.m.Y') ?: 'Нет активности', 'Напомнить клиенту и зафиксировать следующий шаг', $deal->owner?->name, 'high', Sales::getUrl()));
            });

        SalesOpportunity::query()
            ->with(['client', 'owner'])
            ->where('status', 'open')
            ->where('amount', '>=', 200000)
            ->where('probability', '>=', 70)
            ->whereNull('planned_close_at')
            ->limit(20)
            ->get()
            ->each(function (SalesOpportunity $deal) use ($rows): void {
                $rows->push($this->attentionRow('deal', $deal->name ?: 'Сделка #'.$deal->id, 'Большая сделка без следующего действия', $this->money((float) $deal->amount).' · '.$this->percent((float) $deal->probability), 'Назначить следующий шаг и дату закрытия', $deal->owner?->name, 'high', Sales::getUrl()));
            });

        foreach ($this->teamLoadRows($period, $production, $hourRate, $owner) as $employee) {
            if (($employee['utilization_pct'] ?? 0) >= 95) {
                $rows->push($this->attentionRow('employee', $employee['employee'], 'Перегруз сотрудника', $this->percent($employee['utilization_pct']), 'Снять часть задач или перенести сроки', null, 'high', Production::getUrl()));
            } elseif (($employee['utilization_pct'] ?? 0) >= 85) {
                $rows->push($this->attentionRow('employee', $employee['employee'], 'Высокая загрузка сотрудника', $this->percent($employee['utilization_pct']), 'Проверить план на неделю', null, 'medium', Production::getUrl()));
            }

            if (($employee['overdue_tasks'] ?? 0) > 0) {
                $rows->push($this->attentionRow('employee', $employee['employee'], 'Много просроченных задач', $employee['overdue_tasks'].' задач', 'Разобрать просрочки', null, 'medium', Production::getUrl()));
            }
        }

        if (($owner['hours'] ?? 0) >= 60) {
            $rows->push($this->attentionRow('employee', $owner['employee']?->name ?: 'Основатель', 'Собственник перегружен производством', $this->hours((float) $owner['hours']), 'Делегировать рутину и оставить только сложные кейсы', $owner['employee']?->name, 'high', Production::getUrl()));
        }

        return $rows
            ->unique(fn (array $row): string => $row['type'].'|'.$row['object'].'|'.$row['problem'])
            ->sortBy(fn (array $row): int => ['high' => 1, 'medium' => 2, 'low' => 3][$row['priority_key']] ?? 4)
            ->values()
            ->map(function (array $row, int $index): array {
                $row['__key'] = 'attention-'.$index.'-'.$row['type'].'-'.md5($row['object'].$row['problem']);

                return $row;
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function salesSourceRows(AnalyticsPeriod $period): array
    {
        $leadDate = DB::raw('coalesce(lead_created_at, created_at)');
        $leadRows = SalesLead::query()
            ->whereBetween($leadDate, [$period->from, $period->to])
            ->selectRaw("coalesce(nullif(source_channel, ''), 'Другое') as source, count(*) as leads")
            ->groupByRaw("coalesce(nullif(source_channel, ''), 'Другое')")
            ->get()
            ->keyBy('source');

        $sourceSub = SalesLead::query()
            ->selectRaw("distinct on (external_id) external_id as lead_external_id, coalesce(nullif(source_channel, ''), 'Другое') as source_channel")
            ->orderBy('external_id')
            ->orderByDesc('lead_created_at')
            ->orderByDesc('created_at');

        $periodDate = DB::raw('coalesce(sales_opportunities.opened_at, sales_opportunities.created_at)');
        $rows = SalesOpportunity::query()
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->leftJoinSub($sourceSub, 'lead_sources', function ($join) {
                $join->whereRaw("lead_sources.lead_external_id = (sales_opportunities.metadata #>> '{amo_lead,id}')");
            })
            ->whereBetween($periodDate, [$period->from, $period->to])
            ->selectRaw("
                coalesce(nullif(lead_sources.source_channel, ''), sales_opportunities.source_channel, 'Другое') as source,
                count(*) as deals,
                sum(case when sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142' then 1 else 0 end) as won_count,
                sum(case when sales_opportunities.status = 'won' or stages.is_success = true or stages.external_id = '142' then sales_opportunities.amount else 0 end) as revenue,
                sum(case when sales_opportunities.status = 'open' then sales_opportunities.amount else 0 end) as pipeline,
                sum(case when sales_opportunities.status = 'open' then sales_opportunities.amount * coalesce(sales_opportunities.probability, 0) / 100.0 else 0 end) as forecast
            ")
            ->groupByRaw("coalesce(nullif(lead_sources.source_channel, ''), sales_opportunities.source_channel, 'Другое')")
            ->orderByDesc('revenue')
            ->orderByDesc('forecast')
            ->get()
            ->keyBy('source');

        return $leadRows
            ->keys()
            ->merge($rows->keys())
            ->unique()
            ->values()
            ->map(function (string $source, int $index) use ($leadRows, $rows): array {
                $leadRow = $leadRows->get($source);
                $dealRow = $rows->get($source);
                $leads = (int) ($leadRow?->leads ?? $dealRow?->deals ?? 0);
                $won = (int) ($dealRow?->won_count ?? 0);
                $revenue = (float) ($dealRow?->revenue ?? 0);
                $forecast = (float) ($dealRow?->forecast ?? 0);
                $pipeline = (float) ($dealRow?->pipeline ?? 0);
            $conversion = $leads > 0 ? ($won / $leads) * 100 : null;

            return [
                '__key' => 'source-'.$index,
                'source' => $source,
                'leads' => $leads,
                'won_count' => $won,
                'conversion' => $conversion,
                'revenue' => $revenue,
                'average_check' => $won > 0 ? $revenue / $won : 0,
                'pipeline' => $pipeline,
                'forecast' => $forecast,
                'status' => $revenue > 0 ? 'Работает' : ($forecast > 0 ? 'Есть потенциал' : 'Нет результата'),
            ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['revenue'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function riskProjectRows(array $production, float $hourRate): array
    {
        $projects = Project::query()->with(['client', 'manager'])->get()->keyBy('id');

        return collect($production['project_summary'] ?? [])
            ->filter(fn (array $row): bool => ($row['project_status'] ?? null) === 'active' && (float) ($row['hours_progress_pct'] ?? 0) >= 80)
            ->sortByDesc(fn (array $row): float => (float) ($row['hours_progress_pct'] ?? 0))
            ->values()
            ->map(function (array $row) use ($projects): array {
                $project = $projects->get((int) ($row['project_id'] ?? 0));
                $progress = (float) ($row['hours_progress_pct'] ?? 0);

                return [
                    '__key' => 'risk-project-'.($row['project_id'] ?? md5((string) ($row['project_name'] ?? ''))),
                    'project' => (string) ($row['project_name'] ?? 'Проект'),
                    'client' => $project?->client?->name ?: 'Без клиента',
                    'project_type' => $this->projectTypeLabel((string) ($row['project_type'] ?? '')),
                    'responsible' => $project?->manager?->name ?: 'Не назначен',
                    'planned_hours' => (float) ($row['planned_hours_total'] ?? 0),
                    'fact_hours' => (float) ($row['hours'] ?? 0),
                    'progress_pct' => $progress,
                    'overrun_hours' => (float) ($row['overrun_hours'] ?? 0),
                    'missed_profit' => (float) ($row['missed_profit'] ?? 0),
                    'status' => $progress >= 100 ? 'Сверх плана' : 'Риск перерасхода',
                    'next_action' => $progress >= 100 ? 'Пересогласовать лимит/доплату' : 'Проверить остаток плана',
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function teamLoadRows(AnalyticsPeriod $period, array $production, float $hourRate, array $owner): array
    {
        $employeesByUuid = Employee::query()
            ->whereNotNull('weeek_uuid')
            ->get()
            ->keyBy(fn (Employee $employee): string => (string) $employee->weeek_uuid);
        $overdueTasks = Task::query()
            ->selectRaw('assignee_employee_id, count(*) as overdue_count')
            ->whereNotNull('assignee_employee_id')
            ->where('due_at', '<', now())
            ->whereNull('completed_at')
            ->whereNotIn('status', ['done', 'completed', 'closed'])
            ->groupBy('assignee_employee_id')
            ->pluck('overdue_count', 'assignee_employee_id');

        $rows = collect($production['employee_summary'] ?? [])
            ->map(function (array $row) use ($employeesByUuid, $overdueTasks, $hourRate, $owner): array {
                $employeeUuid = (string) data_get($row, 'employee.id', '');
                $employee = $employeesByUuid->get($employeeUuid);
                $hours = (float) ($row['hours'] ?? 0);
                $expected = (float) ($row['expected'] ?? 0);
                $utilization = (float) ($row['utilization_pct'] ?? 0);
                $isOwner = $owner['employee'] && $employee && (int) $employee->id === (int) $owner['employee']->id;

                return [
                    '__key' => 'team-'.$employeeUuid,
                    'employee' => (string) data_get($row, 'employee.name', $employee?->name ?: 'Без сотрудника'),
                    'role' => $isOwner ? 'Основатель' : ($employee?->role_title ?: 'Сотрудник'),
                    'planned_hours' => $expected,
                    'fact_hours' => $hours,
                    'utilization_pct' => $utilization,
                    'active_projects' => count((array) ($row['projects'] ?? [])),
                    'overdue_tasks' => $employee ? (int) ($overdueTasks[$employee->id] ?? 0) : 0,
                    'earned' => $hours * $hourRate,
                    'owner_margin' => (float) ($row['owner_profit'] ?? 0),
                    'status' => $utilization >= 95 ? 'Перегруз' : ($utilization >= 85 ? 'Внимание' : ($hours > 0 ? 'Норма' : 'Нет нагрузки')),
                    'is_owner' => $isOwner,
                ];
            })
            ->sortByDesc(fn (array $row): int => $row['is_owner'] ? 1 : 0)
            ->values();

        if ($owner['employee'] && $rows->where('is_owner', true)->isEmpty()) {
            $rows->prepend([
                '__key' => 'team-owner-empty',
                'employee' => $owner['employee']->name,
                'role' => 'Основатель',
                'planned_hours' => 0,
                'fact_hours' => 0,
                'utilization_pct' => 0,
                'active_projects' => 0,
                'overdue_tasks' => 0,
                'earned' => 0,
                'owner_margin' => 0,
                'status' => 'Нет нагрузки',
                'is_owner' => true,
            ]);
        }

        return $rows->values()->all();
    }

    /**
     * @return array<int, array{client:string, rate:float}>
     */
    protected function clientEffectiveRateRows(AnalyticsPeriod $period): array
    {
        $revenueByClient = RevenueTransaction::query()
            ->selectRaw('client_id, sum(amount) as revenue')
            ->whereNotNull('client_id')
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->groupBy('client_id')
            ->pluck('revenue', 'client_id');

        $hoursByClient = TaskTimeEntry::query()
            ->selectRaw('projects.client_id, sum(task_time_entries.minutes) / 60.0 as hours')
            ->join('tasks', 'tasks.id', '=', 'task_time_entries.task_id')
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->whereNotNull('projects.client_id')
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupBy('projects.client_id')
            ->pluck('hours', 'client_id');

        $clients = Client::query()
            ->whereIn('id', collect($revenueByClient->keys())->merge($hoursByClient->keys())->unique()->values())
            ->pluck('name', 'id');

        return collect($hoursByClient)
            ->map(function ($hours, $clientId) use ($revenueByClient, $clients): array {
                $hours = (float) $hours;
                $revenue = (float) ($revenueByClient[$clientId] ?? 0);

                return [
                    'client' => (string) ($clients[$clientId] ?? 'Клиент #'.$clientId),
                    'rate' => $hours > 0 ? $revenue / $hours : 0,
                ];
            })
            ->values()
            ->all();
    }

    protected function ownerEmployee(): ?Employee
    {
        $ownerId = trim((string) config('dashboard.owner_user_id', ''));
        $ownerEmail = trim((string) config('dashboard.owner_email', ''));

        if ($ownerId !== '') {
            $query = Employee::query();

            if (Str::isUuid($ownerId)) {
                return $query->where('weeek_uuid', $ownerId)->first();
            }

            if (is_numeric($ownerId)) {
                return $query->whereKey((int) $ownerId)->first();
            }
        }

        if ($ownerEmail === '' && auth()->check()) {
            $ownerEmail = (string) auth()->user()?->email;
        }

        if ($ownerEmail !== '') {
            return Employee::query()->whereRaw('lower(email) = ?', [Str::lower($ownerEmail)])->first();
        }

        return null;
    }

    protected function wonDealsQuery(AnalyticsPeriod $period)
    {
        return SalesOpportunity::query()
            ->leftJoin('stages', 'stages.id', '=', 'sales_opportunities.stage_id')
            ->where(function ($query) {
                $query
                    ->where('sales_opportunities.status', 'won')
                    ->orWhere('stages.is_success', true)
                    ->orWhere('stages.external_id', '142');
            })
            ->whereBetween(DB::raw('coalesce(sales_opportunities.won_at, sales_opportunities.closed_at, sales_opportunities.opened_at, sales_opportunities.created_at)'), [$period->from, $period->to]);
    }

    protected function openInvoices()
    {
        return Invoice::query()
            ->where('amount', '>', 0)
            ->where(function ($query) {
                $query
                    ->whereNull('payment_status')
                    ->orWhereRaw("lower(coalesce(payment_status, '')) not like '%оплачен%'")
                    ->orWhereRaw("lower(coalesce(payment_status, '')) like '%не оплачен%'");
            })
            ->whereRaw("lower(coalesce(payment_status, '')) not like '%отмен%'");
    }

    protected function openInvoiceAmount(): float
    {
        return (float) $this->openInvoices()->sum('amount');
    }

    protected function supportMrr(): float
    {
        $supportPipeline = Str::lower((string) config('dashboard.support_pipeline_name', 'Сопровождение'));
        $supportOpenDeals = SalesOpportunity::query()
            ->leftJoin('pipelines', 'pipelines.id', '=', 'sales_opportunities.pipeline_id')
            ->whereRaw('lower(coalesce(pipelines.name, \'\')) = ?', [$supportPipeline])
            ->where('sales_opportunities.status', 'open')
            ->sum('sales_opportunities.amount');

        return (float) $supportOpenDeals;
    }

    protected function expectedPaymentsNext30Days(): float
    {
        return (float) Buyer::query()
            ->whereBetween('next_date', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->sum('next_price');
    }

    protected function payrollCostForPeriod(AnalyticsPeriod $period): float
    {
        $monthlyPayroll = (float) Employee::query()
            ->where('is_active', true)
            ->whereNotNull('salary_amount')
            ->sum('salary_amount');

        if ($monthlyPayroll <= 0) {
            return 0;
        }

        $daysInPeriod = max(1, $period->from->diffInDays($period->to) + 1);
        $daysInMonth = max(1, $period->from->daysInMonth);

        return round($monthlyPayroll * ($daysInPeriod / $daysInMonth), 2);
    }

    protected function netProfitAmountSql(): string
    {
        return Schema::hasColumn('revenue_transactions', 'net_profit_percent')
            ? 'revenue_transactions.amount * coalesce(revenue_transactions.net_profit_percent, 100) / 100.0'
            : 'revenue_transactions.amount';
    }

    protected function attentionRow(string $type, string $object, string $problem, string $metric, string $action, ?string $responsible, string $priority, ?string $url = null): array
    {
        return [
            'type' => $this->typeLabel($type),
            'type_key' => $type,
            'object' => $object,
            'problem' => $problem,
            'metric' => $metric,
            'action' => $action,
            'responsible' => $responsible ?: 'Не назначен',
            'priority' => $this->priorityLabel($priority),
            'priority_key' => $priority,
            'url' => $url,
        ];
    }

    protected function typeLabel(string $type): string
    {
        return [
            'project' => 'Проект',
            'client' => 'Клиент',
            'deal' => 'Сделка',
            'invoice' => 'Счёт',
            'employee' => 'Сотрудник',
            'finance' => 'Финансы',
        ][$type] ?? $type;
    }

    protected function priorityLabel(string $priority): string
    {
        return [
            'high' => 'Высокий',
            'medium' => 'Средний',
            'low' => 'Низкий',
        ][$priority] ?? $priority;
    }

    protected function projectTypeLabel(string $type): string
    {
        return [
            'support_monthly' => 'Ежемесячная',
            'hourly_until_date' => 'Почасовка',
            'hourly_package' => 'Почасовка пакетная',
        ][$type] ?? ($type !== '' ? $type : 'Не указан');
    }

    protected function money(float $value): string
    {
        return number_format($value, 0, ',', ' ').' ₽';
    }

    protected function hours(float $value): string
    {
        return number_format($value, 1, ',', ' ').' ч';
    }

    protected function percent(float $value): string
    {
        return number_format($value, 1, ',', ' ').'%';
    }
}
