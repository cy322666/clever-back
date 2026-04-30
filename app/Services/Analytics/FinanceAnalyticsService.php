<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\Employee;
use App\Models\ExpenseTransaction;
use App\Models\RevenueTransaction;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\Schema;

class FinanceAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $company = $this->company();
        $previousPeriod = $period->previousComparable();

        $revenues = RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to]);
        $previousRevenues = RevenueTransaction::query()
            ->whereBetween('posted_at', [$previousPeriod->from, $previousPeriod->to]);

        $expenses = ExpenseTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to]);
        $cashIn = (float) (clone $revenues)->sum('amount');
        $netProfitSql = $this->netProfitAmountSql();
        $netProfitIn = (float) (clone $revenues)->selectRaw("coalesce(sum({$netProfitSql}), 0) as total")->value('total');
        $payrollCost = $this->payrollCostForPeriod($period);
        $grossMarginAmount = $netProfitIn - $payrollCost;
        $grossMarginPct = $cashIn > 0 ? round(($grossMarginAmount / $cashIn) * 100, 1) : 0;
        $previousCashIn = (float) (clone $previousRevenues)->sum('amount');
        $previousNetProfitIn = (float) (clone $previousRevenues)->selectRaw("coalesce(sum({$netProfitSql}), 0) as total")->value('total');
        $previousPayrollCost = $this->payrollCostForPeriod($previousPeriod);
        $previousGrossMarginAmount = $previousNetProfitIn - $previousPayrollCost;
        $previousGrossMarginPct = $previousCashIn > 0 ? round(($previousGrossMarginAmount / $previousCashIn) * 100, 1) : 0;

        $incomeByDay = (clone $revenues)
            ->selectRaw("date_trunc('day', posted_at)::date as date, sum(amount) as total")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $expenseByDay = (clone $expenses)
            ->selectRaw("date_trunc('day', posted_at)::date as date, sum(amount) as total")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $expenseCats = (clone $expenses)
            ->selectRaw("coalesce(category, 'Прочее') as label, sum(amount) as value")
            ->groupBy('label')
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        $revenueCats = (clone $revenues)
            ->selectRaw("coalesce(channel, 'Прочее') as label, sum(amount) as value")
            ->groupBy('label')
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        $clientsByRevenue = RevenueTransaction::query()
            ->selectRaw("
                coalesce(
                    nullif(trim(clients.name), ''),
                    nullif(trim(bank_statement_rows.counterparty_name), ''),
                    nullif(trim(revenue_transactions.note), ''),
                    'Без клиента'
                ) as label,
                sum(revenue_transactions.amount) as value,
                sum({$netProfitSql}) as net_value,
                string_agg(revenue_transactions.id::text, ',') as revenue_ids,
                min({$this->netProfitPercentSql()}) as min_net_profit_percent,
                max({$this->netProfitPercentSql()}) as max_net_profit_percent
            ")
            ->leftJoin('clients', 'clients.id', '=', 'revenue_transactions.client_id')
            ->leftJoin('bank_statement_rows', 'bank_statement_rows.id', '=', 'revenue_transactions.bank_statement_row_id')
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->groupByRaw("
                coalesce(
                    nullif(trim(clients.name), ''),
                    nullif(trim(bank_statement_rows.counterparty_name), ''),
                    nullif(trim(revenue_transactions.note), ''),
                    'Без клиента'
                )
            ")
            ->orderByDesc('value')
            ->get();

        $revenueRows = RevenueTransaction::query()
            ->selectRaw("
                revenue_transactions.id,
                revenue_transactions.posted_at,
                revenue_transactions.amount as value,
                {$this->netProfitPercentSql()} as net_profit_percent,
                {$netProfitSql} as net_value,
                coalesce(
                    nullif(trim(clients.name), ''),
                    nullif(trim(bank_statement_rows.counterparty_name), ''),
                    nullif(trim(revenue_transactions.note), ''),
                    'Без клиента'
                ) as counterparty,
                coalesce(
                    nullif(trim(bank_statement_rows.purpose), ''),
                    nullif(trim(revenue_transactions.note), ''),
                    nullif(trim(revenue_transactions.source_reference), ''),
                    'Поступление'
                ) as payment_label,
                sum(revenue_transactions.amount) over (
                    partition by coalesce(
                        nullif(trim(clients.name), ''),
                        nullif(trim(bank_statement_rows.counterparty_name), ''),
                        nullif(trim(revenue_transactions.note), ''),
                        'Без клиента'
                    )
                ) as counterparty_value,
                sum({$netProfitSql}) over (
                    partition by coalesce(
                        nullif(trim(clients.name), ''),
                        nullif(trim(bank_statement_rows.counterparty_name), ''),
                        nullif(trim(revenue_transactions.note), ''),
                        'Без клиента'
                    )
                ) as counterparty_net_value,
                count(*) over (
                    partition by coalesce(
                        nullif(trim(clients.name), ''),
                        nullif(trim(bank_statement_rows.counterparty_name), ''),
                        nullif(trim(revenue_transactions.note), ''),
                        'Без клиента'
                    )
                ) as counterparty_transactions_count
            ")
            ->leftJoin('clients', 'clients.id', '=', 'revenue_transactions.client_id')
            ->leftJoin('bank_statement_rows', 'bank_statement_rows.id', '=', 'revenue_transactions.bank_statement_row_id')
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->orderByDesc('counterparty_value')
            ->orderByDesc('posted_at')
            ->orderByDesc('revenue_transactions.id')
            ->get();

        $lowMarginClients = Client::query()
            ->where('margin_target', '<', config('dashboard.thresholds.low_margin_threshold'))
            ->limit(8)
            ->get();

        return [
            'kpis' => [
                ['label' => 'Выручка', 'value' => number_format($cashIn, 0, ',', ' '), 'hint' => 'Все поступления за период', 'tone' => 'emerald', 'comparison' => $this->compareValues($cashIn, $previousCashIn)],
                ['label' => 'Чистыми', 'value' => number_format($netProfitIn, 0, ',', ' '), 'hint' => 'По доле чистыми в поступлениях', 'tone' => 'cyan', 'comparison' => $this->compareValues($netProfitIn, $previousNetProfitIn)],
                ['label' => 'Маржинальность', 'value' => number_format($grossMarginPct, 1, ',', ' ').'%', 'hint' => 'Чистыми минус зарплаты', 'tone' => 'brand', 'comparison' => $this->compareValues($grossMarginPct, $previousGrossMarginPct)],
            ],
            'charts' => [
                'cash_in' => $this->dailySeries($period, $incomeByDay, 'date', 'total'),
                'expense_categories' => $this->namedSeries($expenseCats),
                'revenue_channels' => $this->namedSeries($revenueCats),
            ],
            'top_clients' => $clientsByRevenue,
            'revenue_transactions' => $revenueRows,
            'low_margin_clients' => $lowMarginClients,
            'period' => $period,
        ];
    }

    private function netProfitAmountSql(): string
    {
        return Schema::hasColumn('revenue_transactions', 'net_profit_percent')
            ? 'revenue_transactions.amount * coalesce(revenue_transactions.net_profit_percent, 100) / 100.0'
            : 'revenue_transactions.amount';
    }

    private function netProfitPercentSql(): string
    {
        return Schema::hasColumn('revenue_transactions', 'net_profit_percent')
            ? 'coalesce(revenue_transactions.net_profit_percent, 100)'
            : '100';
    }

    private function payrollCostForPeriod(AnalyticsPeriod $period): float
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
}
