<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\Employee;
use App\Models\ExpenseTransaction;
use App\Models\RevenueTransaction;
use App\Support\AnalyticsPeriod;
use App\Support\FinanceTransactionTypes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $previousPeriod = $period->previousComparable();
        $revenues = RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to]);
        $previousRevenues = RevenueTransaction::query()
            ->whereBetween('posted_at', [$previousPeriod->from, $previousPeriod->to]);
        $expenses = ExpenseTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to]);
        $previousExpenses = ExpenseTransaction::query()
            ->whereBetween('posted_at', [$previousPeriod->from, $previousPeriod->to]);

        $cashIn = (float) (clone $revenues)->sum('amount');
        $expensesTotal = (float) (clone $expenses)->sum('amount');
        $netProfitSql = $this->netProfitAmountSql();
        $netProfitIn = (float) (clone $revenues)->selectRaw("coalesce(sum({$netProfitSql}), 0) as total")->value('total');
        $payrollPlan = $this->payrollCostForPeriod($period);
        $payrollExpense = $this->payrollExpenseForQuery(clone $expenses);
        $payrollCardValue = max($payrollExpense, $payrollPlan);
        $payrollAdjustment = max(0, $payrollPlan - $payrollExpense);
        $netProfit = $netProfitIn - $expensesTotal - $payrollAdjustment;
        $marginPct = $cashIn > 0 ? round(($netProfit / $cashIn) * 100, 1) : null;
        $recurringRevenue = $this->recurringRevenueForQuery(clone $revenues);
        $oneTimeRevenue = max(0, $cashIn - $recurringRevenue);
        $recurringShare = $cashIn > 0 ? round(($recurringRevenue / $cashIn) * 100, 1) : null;

        $previousCashIn = (float) (clone $previousRevenues)->sum('amount');
        $previousExpensesTotal = (float) (clone $previousExpenses)->sum('amount');
        $previousNetProfitIn = (float) (clone $previousRevenues)->selectRaw("coalesce(sum({$netProfitSql}), 0) as total")->value('total');
        $previousPayrollPlan = $this->payrollCostForPeriod($previousPeriod);
        $previousPayrollExpense = $this->payrollExpenseForQuery(clone $previousExpenses);
        $previousNetProfit = $previousNetProfitIn - $previousExpensesTotal - max(0, $previousPayrollPlan - $previousPayrollExpense);
        $previousMarginPct = $previousCashIn > 0 ? round(($previousNetProfit / $previousCashIn) * 100, 1) : null;
        $previousRecurringRevenue = $this->recurringRevenueForQuery(clone $previousRevenues);
        $previousRecurringShare = $previousCashIn > 0 ? round(($previousRecurringRevenue / $previousCashIn) * 100, 1) : null;

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

        $incomeByType = $this->incomeByType($period, $cashIn);
        $expenseByType = $this->expenseByType($period, $expensesTotal);
        $financeTransactions = $this->financeTransactions($period);
        $unclassifiedTransactions = $financeTransactions
            ->filter(fn (array $row): bool => blank($row['transaction_type'] ?? null))
            ->values();

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

        $revenueRows = $this->revenueRows($period, $netProfitSql);
        $lowMarginClients = Client::query()
            ->where('margin_target', '<', config('dashboard.thresholds.low_margin_threshold'))
            ->limit(8)
            ->get();

        return [
            'kpis' => [
                ['label' => 'Выручка всего', 'value' => $this->money($cashIn), 'hint' => 'Все поступления за период', 'tone' => $cashIn > 0 ? 'emerald' : 'gray', 'comparison' => $cashIn > 0 || $previousCashIn > 0 ? $this->compareValues($cashIn, $previousCashIn) : null],
                ['label' => 'Регулярная выручка', 'value' => $this->money($recurringRevenue), 'hint' => 'Сопровождение, лицензии и регулярные оплаты', 'tone' => $recurringRevenue > 0 ? 'cyan' : 'gray'],
                ['label' => 'Разовая выручка', 'value' => $this->money($oneTimeRevenue), 'hint' => 'Внедрение, разработка, аудит, консультации', 'tone' => $oneTimeRevenue > 0 ? 'brand' : 'gray'],
                ['label' => 'Расходы всего', 'value' => $this->money($expensesTotal), 'hint' => 'По финансовым транзакциям', 'tone' => $expensesTotal > 0 ? 'warning' : 'gray', 'comparison' => $expensesTotal > 0 || $previousExpensesTotal > 0 ? $this->compareValues($expensesTotal, $previousExpensesTotal) : null],
                ['label' => 'ФОТ', 'value' => $this->money($payrollCardValue), 'hint' => $payrollExpense > 0 ? 'По расходам типа ФОТ' : 'По зарплатам сотрудников', 'tone' => $payrollCardValue > 0 ? 'amber' : 'gray'],
                ['label' => 'Чистая прибыль', 'value' => $this->money($netProfit), 'hint' => 'Прибыль до зарплат минус расходы и ФОТ', 'tone' => $netProfit < 0 ? 'danger' : ($netProfit > 0 ? 'emerald' : 'gray'), 'comparison' => $cashIn > 0 || $previousCashIn > 0 ? $this->compareValues($netProfit, $previousNetProfit) : null],
                ['label' => 'Маржинальность', 'value' => $marginPct === null ? 'Нет данных' : $this->percent($marginPct), 'hint' => 'Чистая прибыль / выручка', 'tone' => $marginPct === null ? 'gray' : ($marginPct < 20 ? 'warning' : 'success'), 'comparison' => $marginPct !== null && $previousMarginPct !== null ? $this->compareValues($marginPct, $previousMarginPct) : null],
                ['label' => 'Доля регулярной выручки', 'value' => $recurringShare === null ? 'Нет данных' : $this->percent($recurringShare), 'hint' => 'Регулярная / вся выручка', 'tone' => $recurringShare === null ? 'gray' : ($recurringShare < 30 ? 'warning' : 'success')],
            ],
            'charts' => [
                'cash_in' => $this->dailySeries($period, $incomeByDay, 'date', 'total'),
                'cash_out' => $this->dailySeries($period, $expenseByDay, 'date', 'total'),
                'expense_categories' => $this->namedSeries($expenseByType->take(8)->map(fn (array $row): array => ['label' => $row['type'], 'value' => $row['amount']])),
                'revenue_channels' => $this->namedSeries($incomeByType->take(8)->map(fn (array $row): array => ['label' => $row['type'], 'value' => $row['amount']])),
            ],
            'cards_raw' => [
                'revenue' => $cashIn,
                'recurring_revenue' => $recurringRevenue,
                'one_time_revenue' => $oneTimeRevenue,
                'expenses' => $expensesTotal,
                'payroll' => $payrollCardValue,
                'payroll_plan' => $payrollPlan,
                'payroll_expense' => $payrollExpense,
                'net_profit' => $netProfit,
                'margin_pct' => $marginPct,
                'recurring_share' => $recurringShare,
                'previous_revenue' => $previousCashIn,
                'previous_expenses' => $previousExpensesTotal,
                'previous_recurring_share' => $previousRecurringShare,
            ],
            'income_by_type' => $incomeByType,
            'expense_by_type' => $expenseByType,
            'finance_transactions' => $financeTransactions,
            'unclassified_transactions' => $unclassifiedTransactions,
            'top_clients' => $clientsByRevenue,
            'revenue_transactions' => $revenueRows,
            'low_margin_clients' => $lowMarginClients,
            'period' => $period,
        ];
    }

    public function updateTransactionType(string $sourceTable, int $sourceId, ?string $transactionType): void
    {
        $model = match ($sourceTable) {
            'revenue_transactions' => RevenueTransaction::class,
            'expense_transactions' => ExpenseTransaction::class,
            default => null,
        };

        if ($model === null || $sourceId <= 0) {
            return;
        }

        $model::query()
            ->whereKey($sourceId)
            ->update(['transaction_type' => FinanceTransactionTypes::normalize($transactionType)]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function incomeByType(AnalyticsPeriod $period, float $totalRevenue): Collection
    {
        $typeSql = $this->transactionTypeSql('revenue_transactions');

        return RevenueTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->selectRaw("{$typeSql} as type, count(*) as transactions_count, coalesce(sum(amount), 0) as amount")
            ->groupByRaw($typeSql)
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row): array => $this->typeSummaryRow($row, $totalRevenue));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function expenseByType(AnalyticsPeriod $period, float $totalExpenses): Collection
    {
        $typeSql = $this->transactionTypeSql('expense_transactions');

        return ExpenseTransaction::query()
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->selectRaw("{$typeSql} as type, count(*) as transactions_count, coalesce(sum(amount), 0) as amount")
            ->groupByRaw($typeSql)
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row): array => $this->typeSummaryRow($row, $totalExpenses));
    }

    private function typeSummaryRow(object $row, float $total): array
    {
        $amount = (float) ($row->amount ?? 0);
        $count = (int) ($row->transactions_count ?? 0);

        return [
            '__key' => 'type-'.md5((string) ($row->type ?? FinanceTransactionTypes::UNCLASSIFIED)),
            'type' => (string) ($row->type ?? FinanceTransactionTypes::UNCLASSIFIED),
            'transactions_count' => $count,
            'amount' => $amount,
            'share' => $total > 0 ? ($amount / $total) * 100 : null,
            'average_payment' => $count > 0 ? $amount / $count : 0,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function financeTransactions(AnalyticsPeriod $period): Collection
    {
        return $this->revenueTransactionRows($period)
            ->merge($this->expenseTransactionRows($period))
            ->sortByDesc(fn (array $row): string => (string) $row['posted_at'])
            ->values()
            ->map(function (array $row, int $index): array {
                $row['__key'] = $row['source_table'].'-'.$row['source_id'].'-'.$index;

                return $row;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function revenueTransactionRows(AnalyticsPeriod $period): Collection
    {
        $query = RevenueTransaction::query()
            ->selectRaw($this->transactionSelectSql(
                table: 'revenue_transactions',
                direction: "'income'",
                counterpartySql: "coalesce(nullif(trim(clients.name), ''), nullif(trim(bank_statement_rows.counterparty_name), ''), nullif(trim(revenue_transactions.note), ''), 'Без контрагента')",
                commentSql: "coalesce(nullif(trim(bank_statement_rows.purpose), ''), nullif(trim(revenue_transactions.note), ''), nullif(trim(revenue_transactions.source_reference), ''), 'Поступление')"
            ))
            ->leftJoin('clients', 'clients.id', '=', 'revenue_transactions.client_id')
            ->leftJoin('projects', 'projects.id', '=', 'revenue_transactions.project_id')
            ->leftJoin('bank_statement_rows', 'bank_statement_rows.id', '=', 'revenue_transactions.bank_statement_row_id')
            ->whereBetween('posted_at', [$period->from, $period->to]);

        $this->joinInvoicesIfPossible($query, 'revenue_transactions');

        return $query->get()->map(fn ($row): array => $this->transactionRow($row, 'revenue_transactions'));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function expenseTransactionRows(AnalyticsPeriod $period): Collection
    {
        $query = ExpenseTransaction::query()
            ->selectRaw($this->transactionSelectSql(
                table: 'expense_transactions',
                direction: "'expense'",
                counterpartySql: "coalesce(nullif(trim(expense_transactions.vendor_name), ''), nullif(trim(bank_statement_rows.counterparty_name), ''), nullif(trim(clients.name), ''), nullif(trim(expense_transactions.note), ''), 'Без контрагента')",
                commentSql: "coalesce(nullif(trim(bank_statement_rows.purpose), ''), nullif(trim(expense_transactions.note), ''), nullif(trim(expense_transactions.source_reference), ''), 'Расход')"
            ))
            ->leftJoin('clients', 'clients.id', '=', 'expense_transactions.client_id')
            ->leftJoin('projects', 'projects.id', '=', 'expense_transactions.project_id')
            ->leftJoin('bank_statement_rows', 'bank_statement_rows.id', '=', 'expense_transactions.bank_statement_row_id')
            ->whereBetween('posted_at', [$period->from, $period->to]);

        $this->joinInvoicesIfPossible($query, 'expense_transactions');

        return $query->get()->map(fn ($row): array => $this->transactionRow($row, 'expense_transactions'));
    }

    private function transactionSelectSql(string $table, string $direction, string $counterpartySql, string $commentSql): string
    {
        $transactionTypeSql = Schema::hasColumn($table, 'transaction_type') ? "{$table}.transaction_type" : 'null';
        $directionSql = Schema::hasColumn($table, 'direction') ? "coalesce(nullif({$table}.direction, ''), {$direction})" : $direction;
        $invoiceIdSql = Schema::hasColumn($table, 'invoice_id') ? "{$table}.invoice_id" : 'null';
        $invoiceLabelSql = Schema::hasColumn($table, 'invoice_id') && Schema::hasTable('invoices')
            ? "coalesce(nullif(trim(invoices.name), ''), invoices.external_id::text, invoices.id::text)"
            : 'null';

        return "
            {$table}.id as source_id,
            {$table}.posted_at,
            {$table}.amount,
            {$directionSql} as direction,
            {$transactionTypeSql} as transaction_type,
            {$counterpartySql} as counterparty,
            clients.name as client_name,
            projects.name as project_name,
            {$commentSql} as comment,
            {$invoiceIdSql} as invoice_id,
            {$invoiceLabelSql} as invoice_label
        ";
    }

    private function transactionRow(object $row, string $sourceTable): array
    {
        $direction = (string) ($row->direction ?: ($sourceTable === 'revenue_transactions' ? 'income' : 'expense'));
        $type = FinanceTransactionTypes::normalize($row->transaction_type ?? null);

        return [
            'source_table' => $sourceTable,
            'source_id' => (int) $row->source_id,
            'posted_at' => $row->posted_at,
            'counterparty' => (string) ($row->counterparty ?? 'Без контрагента'),
            'client' => (string) ($row->client_name ?? 'Не привязан'),
            'project' => (string) ($row->project_name ?? 'Не привязан'),
            'amount' => (float) ($row->amount ?? 0),
            'direction' => $direction,
            'direction_label' => $direction === 'expense' ? 'Расход' : 'Поступление',
            'transaction_type' => $type,
            'transaction_type_label' => FinanceTransactionTypes::label($type),
            'comment' => (string) ($row->comment ?? ''),
            'invoice_id' => $row->invoice_id ? (int) $row->invoice_id : null,
            'invoice_label' => $row->invoice_label ?: 'Не связан',
        ];
    }

    private function joinInvoicesIfPossible($query, string $table): void
    {
        if (Schema::hasColumn($table, 'invoice_id') && Schema::hasTable('invoices')) {
            $query->leftJoin('invoices', 'invoices.id', '=', "{$table}.invoice_id");
        }
    }

    private function revenueRows(AnalyticsPeriod $period, string $netProfitSql): Collection
    {
        return RevenueTransaction::query()
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
    }

    private function recurringRevenueForQuery($query): float
    {
        $query->where(function ($inner): void {
            $inner->where('is_recurring', true);

            if (Schema::hasColumn('revenue_transactions', 'transaction_type')) {
                $inner->orWhereIn('transaction_type', FinanceTransactionTypes::RECURRING_INCOME);
            }
        });

        return (float) $query->sum('amount');
    }

    private function payrollExpenseForQuery($query): float
    {
        if (! Schema::hasColumn('expense_transactions', 'transaction_type')) {
            return 0;
        }

        return (float) $query->where('transaction_type', 'ФОТ')->sum('amount');
    }

    private function transactionTypeSql(string $table): string
    {
        return Schema::hasColumn($table, 'transaction_type')
            ? "coalesce(nullif(trim({$table}.transaction_type), ''), '".FinanceTransactionTypes::UNCLASSIFIED."')"
            : "'".FinanceTransactionTypes::UNCLASSIFIED."'";
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

    private function money(float $value): string
    {
        return number_format($value, 0, ',', ' ').' ₽';
    }

    private function percent(float $value): string
    {
        return number_format($value, 1, ',', ' ').'%';
    }
}
