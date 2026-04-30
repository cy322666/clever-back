<?php

namespace App\Filament\Widgets;

use App\Support\AnalyticsPeriod;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class EmployeeHoursTableWidget extends ArrayRecordsTableWidget
{
    protected static ?string $heading = 'Часов отработано';

    protected int | string | array $columnSpan = 'full';

    /** @var array{from?: string, to?: string, key?: string} */
    public array $period = [];

    public function mount(array $period = []): void
    {
        $this->period = $period;
    }

    public function table(Table $table): Table
    {
        return parent::table($table)
            ->searchable(false)
            ->paginated(false);
    }

    protected function rows(): array
    {
        $period = $this->resolvePeriod();
        $hourRate = (float) config('dashboard.production_hour_rate', 3000);
        $periodDays = max(1, $period->from->startOfDay()->diffInDays($period->to->startOfDay()) + 1);
        $availableHours = 40 * ($periodDays / 7);

        return DB::table('task_time_entries')
            ->selectRaw("coalesce(task_time_entries.employee_id::text, 'unassigned') as employee_key")
            ->selectRaw("coalesce(max(employees.name), 'Без сотрудника') as name")
            ->selectRaw('coalesce(max(employees.salary_amount), 0) as salary_amount')
            ->selectRaw('coalesce(sum(task_time_entries.minutes), 0) / 60.0 as hours_total')
            ->selectRaw('coalesce(count(task_time_entries.id), 0) as entries_total')
            ->selectRaw('coalesce(sum(task_time_entries.minutes), 0) / 60.0 * ? as earned_total', [$hourRate])
            ->selectRaw(
                "coalesce(sum(task_time_entries.minutes), 0) / 60.0 * ? - coalesce(max(employees.salary_amount), 0) as owner_profit_total",
                [$hourRate]
            )
            ->leftJoin('employees', function ($join) {
                $join->whereRaw('employees.weeek_uuid::text = task_time_entries.employee_id::text');
            })
            ->whereBetween('task_time_entries.entry_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->groupByRaw("coalesce(task_time_entries.employee_id::text, 'unassigned')")
            ->orderByDesc('hours_total')
            ->get()
            ->map(function ($row) use ($availableHours): array {
                $salaryAmount = (float) $row->salary_amount;
                $hourCostByPeriod = ((float) $row->hours_total > 0 && $salaryAmount > 0)
                    ? round($salaryAmount / (float) $row->hours_total, 2)
                    : 0;

                return [
                    'employee_key' => (string) $row->employee_key,
                    '__key' => (string) $row->employee_key,
                    'name' => (string) $row->name,
                    'hours_total' => (float) $row->hours_total,
                    'salary_amount' => $salaryAmount,
                    'hour_cost_by_period' => $hourCostByPeriod,
                    'load_percent' => $availableHours > 0
                        ? round(((float) $row->hours_total / $availableHours) * 100, 1)
                        : 0,
                    'entries_total' => (int) $row->entries_total,
                    'earned_total' => (float) $row->earned_total,
                    'owner_profit_total' => (float) $row->owner_profit_total,
                ];
            })
            ->all();
    }

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('name')
                ->label('Сотрудник')
                ->wrap(),
            TextColumn::make('hours_total')
                ->label('Часы')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . ' ч')
                ->sortable(),
            TextColumn::make('load_percent')
                ->label('Загрузка')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 1, ',', ' ') . '%')
                ->sortable(),
            TextColumn::make('entries_total')
                ->label('Записи')
                ->sortable(),
            TextColumn::make('hour_cost_by_period')
                ->label('Стоимость часа')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
            TextColumn::make('earned_total')
                ->label('Заработано')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
            TextColumn::make('owner_profit_total')
                ->label('Маржа собственника')
                ->formatStateUsing(fn ($state) => number_format((float) $state, 0, ',', ' ') . ' ₽')
                ->sortable(),
        ];
    }

    private function resolvePeriod(): AnalyticsPeriod
    {
        if ($this->period !== []) {
            return AnalyticsPeriod::fromArray($this->period);
        }

        return AnalyticsPeriod::preset('30d');
    }
}
