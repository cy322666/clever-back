<?php

namespace App\Services\Analytics;

use App\Models\Invoice;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InvoiceAnalyticsService extends AnalyticsService
{
    public function build(AnalyticsPeriod $period): array
    {
        $previousPeriod = $period->previousComparable();
        $rows = $this->invoiceRows($period);
        $previousRows = $this->invoiceRows($previousPeriod);

        $totalCount = $rows->count();
        $totalAmount = (float) $rows->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $paidRows = $rows->filter(fn (array $row): bool => $this->isPaidStatus((string) ($row['payment_status'] ?? '')));
        $paidAmount = (float) $paidRows->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $openRows = $rows->reject(fn (array $row): bool => $this->isPaidStatus((string) ($row['payment_status'] ?? '')) || $this->isCancelledStatus((string) ($row['payment_status'] ?? '')));
        $openAmount = (float) $openRows->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $averageAmount = $totalCount > 0 ? $totalAmount / $totalCount : 0;

        $previousTotalCount = $previousRows->count();
        $previousTotalAmount = (float) $previousRows->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $previousPaidAmount = (float) $previousRows
            ->filter(fn (array $row): bool => $this->isPaidStatus((string) ($row['payment_status'] ?? '')))
            ->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $previousOpenAmount = (float) $previousRows
            ->reject(fn (array $row): bool => $this->isPaidStatus((string) ($row['payment_status'] ?? '')) || $this->isCancelledStatus((string) ($row['payment_status'] ?? '')))
            ->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $previousAverageAmount = $previousTotalCount > 0 ? $previousTotalAmount / $previousTotalCount : 0;

        $statusBreakdown = $rows
            ->groupBy(fn (array $row) => (string) ($row['payment_status'] ?? 'Не указан'))
            ->map(fn (Collection $group, string $status) => [
                'status' => $status,
                'rows_count' => $group->count(),
                'total_amount' => (float) $group->sum(fn (array $row): float => (float) ($row['amount'] ?? 0)),
            ])
            ->sortByDesc('total_amount')
            ->values();

        return [
            'kpis' => [
                [
                    'label' => 'Счетов',
                    'value' => number_format($totalCount),
                    'hint' => 'В выбранном периоде',
                    'tone' => 'brand',
                    'comparison' => $this->compareValues($totalCount, $previousTotalCount),
                ],
                [
                    'label' => 'Сумма выставленных счетов',
                    'value' => number_format($totalAmount, 0, ',', ' ') . ' ₽',
                    'hint' => 'Все счета',
                    'tone' => 'cyan',
                    'comparison' => $this->compareValues($totalAmount, $previousTotalAmount),
                ],
                [
                    'label' => 'Оплачено',
                    'value' => number_format($paidAmount, 0, ',', ' ') . ' ₽',
                    'hint' => 'Статус оплачено',
                    'tone' => 'emerald',
                    'comparison' => $this->compareValues($paidAmount, $previousPaidAmount),
                ],
                [
                    'label' => 'Ожидает оплаты',
                    'value' => number_format($openAmount, 0, ',', ' ') . ' ₽',
                    'hint' => 'Не оплаченные счета',
                    'tone' => 'amber',
                    'comparison' => $this->compareValues($openAmount, $previousOpenAmount),
                ],
                [
                    'label' => 'Средний счёт',
                    'value' => number_format($averageAmount, 0, ',', ' ') . ' ₽',
                    'hint' => 'Средняя сумма',
                    'tone' => 'slate',
                    'comparison' => $this->compareValues($averageAmount, $previousAverageAmount),
                ],
            ],
            'rows' => $rows->sortByDesc('amount')->values()->all(),
            'status_breakdown' => $statusBreakdown,
            'paid_amount' => $paidAmount,
            'open_amount' => $openAmount,
            'total_amount' => $totalAmount,
            'period' => $period,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function invoiceRows(AnalyticsPeriod $period): Collection
    {
        $periodColumn = Schema::hasColumn('invoices', 'invoice_date')
            ? 'coalesce(invoices.invoice_date, invoices.created_at)'
            : 'invoices.created_at';

        return Invoice::query()
            ->when($period->key !== 'all', function ($query) use ($period, $periodColumn): void {
                $query->whereBetween(DB::raw($periodColumn), [
                    $period->from->toDateTimeString(),
                    $period->to->toDateTimeString(),
                ]);
            })
            ->orderByDesc('amount')
            ->orderByDesc('id')
            ->get()
            ->map(function (Invoice $invoice): array {
                $paymentStatus = trim((string) $invoice->payment_status);

                return [
                    'id' => (string) $invoice->id,
                    '__key' => (string) $invoice->id,
                    'name' => (string) $invoice->name,
                    'customer_name' => trim((string) $invoice->customer_name) !== '' ? (string) $invoice->customer_name : 'Без клиента',
                    'category' => (string) ($invoice->category ?? ''),
                    'amount' => (float) $invoice->amount,
                    'payment_status' => $paymentStatus !== '' ? $paymentStatus : 'Не указан',
                    'invoice_date' => optional($invoice->invoice_date)?->toDateTimeString(),
                    'invoice_link' => $invoice->invoice_link,
                ];
            })
            ->values();
    }

    protected function isPaidStatus(string $status): bool
    {
        $status = mb_strtolower(trim($status));

        return $status !== '' && str_contains($status, 'оплачен');
    }

    protected function isCancelledStatus(string $status): bool
    {
        $status = mb_strtolower(trim($status));

        return $status !== '' && str_contains($status, 'отмен');
    }
}
