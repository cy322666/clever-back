<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Production;
use App\Models\Employee;
use App\Services\Analytics\ProductionAnalyticsService;
use App\Support\AnalyticsPeriod;
use Carbon\CarbonPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

class EmployeeProductionChartWidget extends ChartWidget
{
    protected ?string $heading = 'Отработанные часы по дням';

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '360px';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public ?string $employee = null;

    public function mount(array $period = [], ?string $employee = null): void
    {
        $this->period = $period;
        $this->employee = $employee;
        $this->filter = $employee;
    }

    public function getHeading(): string | Htmlable | null
    {
        return 'Отработанные часы по дням: ' . $this->selectedEmployeeName();
    }

    public function getDescription(): string | Htmlable | null
    {
        return 'Период: ' . $this->resolvePeriod()->label();
    }

    /**
     * @return array<string, string>
     */
    protected function getFilters(): ?array
    {
        return $this->employeeOptions();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $period = $this->resolvePeriod();
        $service = app(ProductionAnalyticsService::class);
        $summary = collect($service->build($period)['employee_summary'] ?? []);
        $row = $this->selectedEmployeeRow($summary);
        $daily = (array) ($row['daily'] ?? []);

        $labels = [];
        $values = [];

        foreach (CarbonPeriod::create($period->from->startOfDay(), '1 day', $period->to->startOfDay()) as $day) {
            $key = $day->toDateString();
            $labels[] = $day->format('d.m');
            $values[] = (float) data_get($daily, $key . '.hours', 0);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Отработано часов',
                    'data' => $values,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.75)',
                    'borderColor' => 'rgba(59, 130, 246, 1)',
                    'borderWidth' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): array|RawJs|null
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ];
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
            'daily' => [],
        ];
    }

    private function selectedEmployeeName(): string
    {
        $employeeKey = $this->resolveEmployeeKey();

        if ($employeeKey !== null) {
            $name = Employee::query()
                ->where('weeek_uuid', $employeeKey)
                ->get(['name'])
                ->reject(fn (Employee $employee): bool => $this->isExcludedProductionEmployee((string) $employee->name))
                ->pluck('name')
                ->first();

            if (filled($name)) {
                return (string) $name;
            }
        }

        $name = Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->get(['name'])
            ->reject(fn (Employee $employee): bool => $this->isExcludedProductionEmployee((string) $employee->name))
            ->pluck('name')
            ->first() ?: 'Без сотрудника';

        return (string) $name;
    }

    private function resolveEmployeeKey(): ?string
    {
        if (filled($this->filter)) {
            return (string) $this->filter;
        }

        if (filled($this->employee)) {
            return (string) $this->employee;
        }

        return Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->get(['name', 'weeek_uuid'])
            ->reject(fn (Employee $employee): bool => $this->isExcludedProductionEmployee((string) $employee->name))
            ->pluck('weeek_uuid')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function employeeOptions(): array
    {
        return Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->get(['name', 'weeek_uuid'])
            ->reject(fn (Employee $employee): bool => $this->isExcludedProductionEmployee((string) $employee->name))
            ->pluck('name', 'weeek_uuid')
            ->all();
    }

    private function isExcludedProductionEmployee(string $name): bool
    {
        $normalizedName = mb_strtolower(trim($name));

        if ($normalizedName === '') {
            return false;
        }

        foreach ((array) config('dashboard.production_excluded_employee_names', []) as $excludedName) {
            $normalizedExcludedName = mb_strtolower(trim((string) $excludedName));

            if ($normalizedExcludedName !== '' && str_contains($normalizedName, $normalizedExcludedName)) {
                return true;
            }
        }

        return false;
    }

    public function updatedFilter(): void
    {
        $query = request()->query();

        if (filled($this->filter)) {
            $query['employee'] = $this->filter;
        } else {
            unset($query['employee']);
        }

        $this->redirect(Production::getUrl($query));
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
