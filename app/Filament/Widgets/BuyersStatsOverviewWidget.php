<?php

namespace App\Filament\Widgets;

use App\Models\Buyer;
use App\Support\AnalyticsPeriod;

class BuyersStatsOverviewWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Продления';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    protected function statsData(): array
    {
        $period = $this->resolvePeriod();
        $buyers = Buyer::query();
        $nextPayments = Buyer::query()
            ->whereBetween('next_date', [$period->from, $period->to]);

        $totalBuyers = (int) (clone $buyers)->count();
        $buyersWithPurchases = (int) (clone $buyers)->whereRaw('coalesce(purchases_count, 0) > 0')->count();
        $nextPaymentsCount = (int) (clone $nextPayments)->count();
        $nextPaymentsAmount = (float) (clone $nextPayments)->sum('next_price');
        $ltvTotal = (float) (clone $buyers)->sum('ltv');

        return [
            ['label' => 'Клиентов', 'value' => number_format($totalBuyers), 'hint' => 'Клиенты из amoCRM', 'tone' => 'brand'],
            ['label' => 'С оплатами', 'value' => number_format($buyersWithPurchases), 'hint' => 'Есть оплаты', 'tone' => 'emerald'],
            ['label' => 'Следующие оплаты', 'value' => number_format($nextPaymentsCount), 'hint' => $period->label(), 'tone' => 'amber'],
            ['label' => 'Сумма следующих оплат', 'value' => number_format($nextPaymentsAmount, 0, ',', ' ') . ' ₽', 'hint' => $period->label(), 'tone' => 'cyan'],
            ['label' => 'LTV', 'value' => number_format($ltvTotal, 0, ',', ' ') . ' ₽', 'hint' => 'По всем клиентам', 'tone' => 'slate'],
        ];
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('month');
    }
}
