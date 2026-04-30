<?php

namespace App\Filament\Widgets;

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
    protected ?string $heading = 'Часы по дням';

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
        return 'Часы по дням: ' . $this->selectedEmployeeName();
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
                    'label' => 'Часы',
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
        if (filled($this->filter)) {
            return (string) $this->filter;
        }

        if (filled($this->employee)) {
            return (string) $this->employee;
        }

        return Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->value('weeek_uuid');
    }

    /**
     * @return array<string, string>
     */
    private function employeeOptions(): array
    {
        return Employee::query()
            ->whereNotNull('weeek_uuid')
            ->orderBy('name')
            ->pluck('name', 'weeek_uuid')
            ->all();
    }

    public function updatedFilter(): void
    {
        $query = request()->query();

        if (filled($this->filter)) {
            $query['employee'] = $this->filter;
        } else {
            unset($query['employee']);
        }

        $this->redirect(request()->url() . '?' . http_build_query($query), navigate: true);
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
