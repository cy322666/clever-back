<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ManualAdjustmentsRecentTableWidget;
use App\Models\Employee;
use App\Models\ManualAdjustment;
use App\Models\Project;
use App\Services\CompanyResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Filament\Schemas\Components\Utilities\Get;

class ManualAdjustments extends AnalyticsPage
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;
    protected static ?string $navigationLabel = 'Ручной ввод';
    protected static ?int $navigationSort = 9;

    protected function widgets(): array
    {
        return [
            $this->widget(ManualAdjustmentsRecentTableWidget::class),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = parent::getHeaderActions();
        app(CompanyResolver::class)->resolve();

        return array_merge($actions, [
            Action::make('projectStatus')
                ->label('Статус проекта')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->form([
                    Select::make('project_id')
                        ->label('Проект')
                        ->required()
                        ->options(Project::query()
                            ->orderByRaw("case when status = 'active' then 0 else 1 end")
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('project_status')
                        ->label('Статус')
                        ->required()
                        ->options([
                            'active' => 'В работе',
                            'inactive' => 'Не в работе',
                        ]),
                    DatePicker::make('adjustment_date')
                        ->label('Дата')
                        ->default(now()),
                    Textarea::make('note')->label('Комментарий')->rows(3),
                ])
                ->action(function (array $data): void {
                    $project = Project::query()
                        ->findOrFail((int) $data['project_id']);

                    $previousStatus = $project->status;
                    $project->status = $data['project_status'];
                    $project->save();

                    ManualAdjustment::query()->create([
                        'user_id' => Auth::id(),
                        'entity_type' => 'project',
                        'entity_id' => $project->id,
                        'adjustment_type' => 'project_status',
                        'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
                        'note' => $data['note'] ?? null,
                        'metadata' => [
                            'project_name' => $project->name,
                            'previous_status' => $previousStatus,
                            'new_status' => $project->status,
                        ],
                    ]);
                }),
            Action::make('projectParameters')
                ->label('Параметры проекта')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->form([
                    Select::make('project_id')
                        ->label('Проект')
                        ->required()
                        ->options(Project::query()
                            ->orderByRaw("case when status = 'active' then 0 else 1 end")
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('project_type')
                        ->label('Тип проекта')
                        ->required()
                        ->default('hourly_until_date')
                        ->options([
                            'support_monthly' => 'Сопровождение ежемесячное',
                            'hourly_until_date' => 'Почасовка до даты',
                            'hourly_package' => 'Пакетная почасовка',
                        ]),
                    DatePicker::make('start_date')
                        ->label('Дата старта')
                        ->required(),
                    DatePicker::make('due_date')
                        ->label('Дата завершения')
                        ->visible(fn (Get $get): bool => $get('project_type') === 'hourly_until_date')
                        ->required(fn (Get $get): bool => $get('project_type') === 'hourly_until_date'),
                    Textarea::make('note')->label('Комментарий')->rows(3),
                ])
                ->action(function (array $data): void {
                    $project = Project::query()
                        ->findOrFail((int) $data['project_id']);

                    $previousProjectType = $project->project_type;
                    $previousStartDate = $project->start_date?->toDateString();
                    $previousDueDate = $project->due_date?->toDateString();

                    $project->project_type = $data['project_type'];
                    $project->start_date = $data['start_date'];
                    $project->due_date = $data['project_type'] === 'hourly_until_date'
                        ? ($data['due_date'] ?? null)
                        : null;
                    $project->save();

                    ManualAdjustment::query()->create([
                        'user_id' => Auth::id(),
                        'entity_type' => 'project',
                        'entity_id' => $project->id,
                        'adjustment_type' => 'project_parameters',
                        'adjustment_date' => now()->toDateString(),
                        'note' => $data['note'] ?? null,
                        'metadata' => [
                            'project_name' => $project->name,
                            'previous_project_type' => $previousProjectType,
                            'new_project_type' => $project->project_type,
                            'previous_start_date' => $previousStartDate,
                            'new_start_date' => $project->start_date?->toDateString(),
                            'previous_due_date' => $previousDueDate,
                            'new_due_date' => $project->due_date?->toDateString(),
                        ],
                    ]);
                }),
            Action::make('employeeSalary')
                ->label('Зарплата сотрудника')
                ->icon(Heroicon::OutlinedBanknotes)
                ->form([
                    Select::make('employee_id')
                        ->label('Сотрудник')
                        ->required()
                        ->options(Employee::query()
                            ->orderByRaw("case when is_active = true then 0 else 1 end")
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    TextInput::make('salary_amount')
                        ->label('Зарплата')
                        ->required()
                        ->numeric()
                        ->minValue(0),
                    DatePicker::make('adjustment_date')
                        ->label('Дата')
                        ->default(now()),
                    Textarea::make('note')->label('Комментарий')->rows(3),
                ])
                ->action(function (array $data): void {
                    $employee = Employee::query()
                        ->findOrFail((int) $data['employee_id']);

                    $previousSalary = $employee->salary_amount;
                    $previousHourlyCost = $employee->hourly_cost;
                    $monthlyNormHours = max(1, (float) $employee->capacity_hours_per_week * 4.333333);
                    $hourlyCost = round(((float) $data['salary_amount']) / $monthlyNormHours, 2);

                    $employee->salary_amount = $data['salary_amount'];
                    $employee->hourly_cost = $hourlyCost;
                    $employee->save();

                    ManualAdjustment::query()->create([
                        'user_id' => Auth::id(),
                        'entity_type' => 'employee',
                        'entity_id' => $employee->id,
                        'adjustment_type' => 'employee_salary',
                        'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
                        'note' => $data['note'] ?? null,
                        'metadata' => [
                            'employee_name' => $employee->name,
                            'previous_salary' => $previousSalary,
                            'new_salary' => (float) $data['salary_amount'],
                            'previous_hourly_cost' => $previousHourlyCost,
                            'new_hourly_cost' => $hourlyCost,
                            'monthly_norm_hours' => round($monthlyNormHours, 2),
                        ],
                    ]);
                }),
            Action::make('projectAdjustment')
                ->label('Новая корректировка')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->form([
                    Select::make('project_id')
                        ->label('Проект')
                        ->required()
                        ->options(Project::query()
                            ->where('status', 'active')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all()),
                    Select::make('adjustment_type')
                        ->label('Тип')
                        ->required()
                        ->options([
                            'budget_change' => 'Изменение бюджета',
                            'hours_change' => 'Изменение часов',
                            'scope_change' => 'Изменение объёма',
                            'risk_note' => 'Пометка риска',
                            'other' => 'Другое',
                        ]),
                    DatePicker::make('adjustment_date')
                        ->label('Дата')
                        ->default(now()),
                    TextInput::make('amount_decimal')->label('Сумма')->numeric(),
                    TextInput::make('hours_decimal')->label('Часы')->numeric(),
                    Textarea::make('note')->label('Комментарий')->rows(3),
                ])
                ->action(function (array $data): void {
                    $project = Project::query()
                        ->where('status', 'active')
                        ->findOrFail((int) $data['project_id']);

                    ManualAdjustment::query()->create([
                        'user_id' => Auth::id(),
                        'entity_type' => 'project',
                        'entity_id' => $project->id,
                        'adjustment_type' => $data['adjustment_type'],
                        'adjustment_date' => $data['adjustment_date'] ?? now()->toDateString(),
                        'amount_decimal' => $data['amount_decimal'] ?? null,
                        'hours_decimal' => $data['hours_decimal'] ?? null,
                        'note' => $data['note'] ?? null,
                    ]);
                }),
        ]);
    }
}
