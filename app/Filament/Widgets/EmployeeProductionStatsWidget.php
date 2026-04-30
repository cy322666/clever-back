<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Services\Analytics\ProductionAnalyticsService;
use App\Support\AnalyticsPeriod;
use Illuminate\Support\Collection;

class EmployeeProductionStatsWidget extends AnalyticsStatsOverviewWidget
{
    protected ?string $heading = 'Аналитика сотрудника';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public ?string $employee = null;

    public function mount(array $period = [], ?string $employee = null): void
    {
        $this->period = $period;
        $this->employee = $employee;
    }

    protected function statsData(): array
    {
        $period = $this->resolvePeriod();
        $service = app(ProductionAnalyticsService::class);
        $current = $this->selectedEmployeeRow(collect($service->build($period)['employee_summary'] ?? []));
        $previous = $this->selectedEmployeeRow(collect($service->build($period->previousComparable())['employee_summary'] ?? []));

        $hours = (float) ($current['hours'] ?? 0);
        $entries = (int) ($current['entries'] ?? 0);
        $earned = (float) ($current['earned'] ?? 0);
        $ownerProfit = (float) ($current['owner_profit'] ?? 0);
        $hourCostByPeriod = (float) ($current['hour_cost_by_period'] ?? 0);
        $utilizationPct = (float) ($current['utilization_pct'] ?? 0);
        $expected = (float) ($current['expected'] ?? 0);
        $previousHours = (float) ($previous['hours'] ?? 0);
        $previousEarned = (float) ($previous['earned'] ?? 0);
        $previousOwnerProfit = (float) ($previous['owner_profit'] ?? 0);
        $previousUtilizationPct = (float) ($previous['utilization_pct'] ?? 0);

        return [
            [
                'label' => 'Часы факт',
                'value' => number_format($hours, 1, ',', ' ') . ' ч',
                'hint' => $entries . ' записей',
                'tone' => 'cyan',
                'comparison' => $this->compareValues($hours, $previousHours),
            ],
            [
                'label' => 'Загрузка',
                'value' => number_format($utilizationPct, 1, ',', ' ') . '%',
                'hint' => 'Норма ' . number_format($expected, 1, ',', ' ') . ' ч',
                'tone' => $utilizationPct >= 100 ? 'danger' : ($utilizationPct >= 80 ? 'amber' : 'emerald'),
                'comparison' => $this->compareValues($utilizationPct, $previousUtilizationPct),
            ],
            [
                'label' => 'Заработано',
                'value' => number_format($earned, 0, ',', ' ') . ' ₽',
                'hint' => '3000 ₽ / час',
                'tone' => 'emerald',
                'comparison' => $this->compareValues($earned, $previousEarned),
            ],
            [
                'label' => 'Стоимость часа',
                'value' => number_format($hourCostByPeriod, 0, ',', ' ') . ' ₽',
                'hint' => 'ЗП / часы в периоде',
                'tone' => 'indigo',
                'comparison' => $this->compareValues($hourCostByPeriod, (float) ($previous['hour_cost_by_period'] ?? 0)),
            ],
            [
                'label' => 'Маржа собственника',
                'value' => number_format($ownerProfit, 0, ',', ' ') . ' ₽',
                'hint' => 'Часы × 3000 - зарплата',
                'tone' => $ownerProfit < 0 ? 'danger' : 'cyan',
                'comparison' => $this->compareValues($ownerProfit, $previousOwnerProfit),
            ],
        ];
    }

    protected function getHeading(): ?string
    {
        return 'Аналитика сотрудника: ' . $this->selectedEmployeeName();
    }

    protected function getDescription(): ?string
    {
        return 'Период: ' . $this->resolvePeriod()->label();
    }

    private function selectedEmployeeRow(Collection $rows): array
    {
        $employeeKey = $this->resolveEmployeeKey();

        if ($employeeKey !== null) {
            $selected = $rows->first(function (array $row) use ($employeeKey): bool {
                return (string) data_get($row, 'employee.id') === $employeeKey;
            });

            if (is_array($selected)) {
                return $selected;
            }
        }

        return $rows->first() ?? [
            'employee' => (object) [
                'id' => $employeeKey ?? 'unassigned',
                'name' => $this->selectedEmployeeName(),
            ],
            'hours' => 0,
            'earned' => 0,
            'owner_profit' => 0,
            'hour_cost_by_period' => 0,
            'utilization_pct' => 0,
            'expected' => 0,
            'entries' => 0,
        ];
    }

    private function selectedEmployeeName(): string
    {
        $employeeKey = $this->resolveEmployeeKey();

        if ($employeeKey !== null) {
            $name = Employee::query()
                ->where('weeek_uuid', $employeeKey)
                ->value('name');

            if (filled($name)) {
                return (string) $name;
            }
        }

        $name = Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->value('name') ?: 'Без сотрудника';

        return (string) $name;
    }

    private function resolveEmployeeKey(): ?string
    {
        return filled($this->employee) ? (string) $this->employee : null;
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }

    /**
     * @return array{current: float, previous: float, delta: float, delta_percent: float|null, direction: string}
     */
    private function compareValues(float|int $current, float|int $previous): array
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
