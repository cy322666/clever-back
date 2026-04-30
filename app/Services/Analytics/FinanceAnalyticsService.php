<?php

namespace App\Services\Analytics;

use App\Models\Client;
use App\Models\ExpenseTransaction;
use App\Models\RevenueTransaction;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Facades\DB;

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
        $previousExpenses = ExpenseTransaction::query()
            ->whereBetween('posted_at', [$previousPeriod->from, $previousPeriod->to]);

        $cashIn = (float) (clone $revenues)->sum('amount');
        $cashOut = (float) (clone $expenses)->sum('amount');
        $grossMargin = $cashIn - $cashOut;
        $grossMarginPct = $cashIn > 0 ? round(($grossMargin / $cashIn) * 100, 1) : 0;
        $previousCashIn = (float) (clone $previousRevenues)->sum('amount');
        $previousCashOut = (float) (clone $previousExpenses)->sum('amount');
        $previousGrossMargin = $previousCashIn - $previousCashOut;
        $previousGrossMarginPct = $previousCashIn > 0 ? round(($previousGrossMargin / $previousCashIn) * 100, 1) : 0;

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
            ->selectRaw('clients.name as label, sum(revenue_transactions.amount) as value')
            ->join('clients', 'clients.id', '=', 'revenue_transactions.client_id')
            ->whereBetween('posted_at', [$period->from, $period->to])
            ->groupBy('clients.name')
            ->orderByDesc('value')
            ->get();

        $lowMarginClients = Client::query()
            ->where('margin_target', '<', config('dashboard.thresholds.low_margin_threshold'))
            ->limit(8)
            ->get();

        return [
            'kpis' => [
                ['label' => 'Поступления', 'value' => number_format($cashIn, 0, ',', ' '), 'hint' => 'За период', 'tone' => 'emerald', 'comparison' => $this->compareValues($cashIn, $previousCashIn)],
                ['label' => 'Чистый поток', 'value' => number_format($grossMargin, 0, ',', ' '), 'hint' => 'Cashflow', 'tone' => $grossMargin >= 0 ? 'cyan' : 'amber', 'comparison' => $this->compareValues($grossMargin, $previousGrossMargin)],
                ['label' => 'Маржинальность', 'value' => number_format($grossMarginPct, 1, ',', ' ').'%', 'hint' => 'Валовая', 'tone' => 'brand', 'comparison' => $this->compareValues($grossMarginPct, $previousGrossMarginPct)],
            ],
            'charts' => [
                'cash_in' => $this->dailySeries($period, $incomeByDay, 'date', 'total'),
                'expense_categories' => $this->namedSeries($expenseCats),
                'revenue_channels' => $this->namedSeries($revenueCats),
            ],
            'top_clients' => $clientsByRevenue,
            'low_margin_clients' => $lowMarginClients,
            'period' => $period,
        ];
    }
}
