<?php

namespace App\Services\Analytics;

use App\Services\CompanyResolver;
use App\Support\AccountContext;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

abstract class AnalyticsService
{
    public function __construct(protected CompanyResolver $companyResolver) {}

    protected function company(): AccountContext
    {
        return $this->companyResolver->resolve();
    }

    /**
     * @param  iterable<object>  $rows
     */
    protected function dailySeries(AnalyticsPeriod $period, iterable $rows, string $dateKey = 'date', string $valueKey = 'total'): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) data_get($row, $dateKey)] = (float) data_get($row, $valueKey, 0);
        }

        $labels = [];
        $values = [];

        foreach (CarbonPeriod::create($period->from->startOfDay(), '1 day', $period->to->startOfDay()) as $day) {
            $key = $day->toDateString();
            $labels[] = $day->format('d.m');
            $values[] = $indexed[$key] ?? 0;
        }

        return compact('labels', 'values');
    }

    /**
     * @param  Collection<int, mixed>  $rows
     */
    protected function namedSeries(Collection $rows, string $labelKey = 'label', string $valueKey = 'value'): array
    {
        return [
            'labels' => $rows->pluck($labelKey)->values()->all(),
            'values' => $rows->pluck($valueKey)->map(fn ($value) => (float) $value)->values()->all(),
        ];
    }

    protected function weeksInPeriod(AnalyticsPeriod $period): float
    {
        return max(1, $period->from->diffInDays($period->to) / 7);
    }

    protected function safeFloat(mixed $value): float
    {
        return (float) ($value ?? 0);
    }

    /**
     * @return array{current: float, previous: float, delta: float, delta_percent: float|null, direction: string}
     */
    protected function compareValues(float|int $current, float|int $previous): array
    {
        $currentValue = (float) $current;
        $previousValue = (float) $previous;
        $delta = $currentValue - $previousValue;
        $deltaPercent = $previousValue !== 0.0 ? round(($delta / abs($previousValue)) * 100, 1) : null;
        $direction = match (true) {
            $delta > 0 => 'up',
            $delta < 0 => 'down',
            default => 'flat',
        };

        return [
            'current' => $currentValue,
            'previous' => $previousValue,
            'delta' => round($delta, 1),
            'delta_percent' => $deltaPercent,
            'direction' => $direction,
        ];
    }
}
