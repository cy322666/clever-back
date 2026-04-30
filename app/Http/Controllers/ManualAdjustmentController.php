<?php

namespace App\Http\Controllers;

use App\Models\ManualAdjustment;
use App\Models\Project;
use App\Services\CompanyResolver;
use App\Support\AnalyticsPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ManualAdjustmentController extends Controller
{
    public function index(Request $request): View
    {
        $period = AnalyticsPeriod::fromRequest($request);
        app(CompanyResolver::class)->resolve();

        return view('manual-adjustments.index', [
            'period' => $period,
            'projects' => Project::query()
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->orderBy('name')
                ->get(),
            'activeProjects' => Project::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
            'employees' => \App\Models\Employee::query()
                ->orderByRaw("case when is_active = true then 0 else 1 end")
                ->orderBy('name')
                ->get(),
            'adjustments' => ManualAdjustment::query()
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }

    public function store(Request $request, CompanyResolver $companyResolver): RedirectResponse
    {
        $companyResolver->resolve();
        $kind = (string) $request->input('kind', 'manual_adjustment');

        if ($kind === 'project_status') {
            $data = $request->validate([
                'project_id' => ['required', 'integer'],
                'project_status' => ['required', 'in:active,inactive'],
                'adjustment_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);
            $data['adjustment_date'] = $data['adjustment_date'] ?? now()->toDateString();

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
                'adjustment_date' => $data['adjustment_date'],
                'note' => $data['note'] ?? null,
                'metadata' => [
                    'project_name' => $project->name,
                    'previous_status' => $previousStatus,
                    'new_status' => $project->status,
                ],
            ]);

            return back()->with('status', 'Статус проекта обновлён.');
        }

        if ($kind === 'employee_salary') {
            $data = $request->validate([
                'employee_id' => ['required', 'integer'],
                'salary_amount' => ['required', 'numeric', 'min:0'],
                'adjustment_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);
            $data['adjustment_date'] = $data['adjustment_date'] ?? now()->toDateString();

            $employee = \App\Models\Employee::query()
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
                'adjustment_date' => $data['adjustment_date'],
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

            return back()->with('status', 'Зарплата сотрудника обновлена.');
        }

        if ($kind === 'project_parameters') {
            $data = $request->validate([
                'project_id' => ['required', 'integer'],
                'project_type' => ['required', 'in:support_monthly,hourly_until_date,hourly_package'],
                'start_date' => ['required', 'date'],
                'due_date' => ['nullable', 'date'],
                'note' => ['nullable', 'string'],
            ]);

            if ($data['project_type'] === 'hourly_until_date' && empty($data['due_date'])) {
                return back()->withErrors(['due_date' => 'Дата завершения обязательна для почасового проекта.']);
            }

            $project = Project::query()
                ->findOrFail((int) $data['project_id']);

            $previousProjectType = $project->project_type;
            $previousStartDate = $project->start_date?->toDateString();
            $previousDueDate = $project->due_date?->toDateString();

            $project->project_type = $data['project_type'];
            $project->start_date = $data['start_date'];
            $project->due_date = $data['project_type'] === 'hourly_until_date' ? $data['due_date'] : null;
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

            return back()->with('status', 'Параметры проекта обновлены.');
        }

        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'adjustment_type' => ['required', 'in:budget_change,hours_change,scope_change,risk_note,other'],
            'adjustment_date' => ['nullable', 'date'],
            'amount_decimal' => ['nullable', 'numeric'],
            'hours_decimal' => ['nullable', 'numeric'],
            'note' => ['nullable', 'string'],
        ]);
        $data['adjustment_date'] = $data['adjustment_date'] ?? now()->toDateString();

        $project = Project::query()
            ->where('status', 'active')
            ->findOrFail((int) $data['project_id']);

        $data['user_id'] = Auth::id();
        $data['entity_type'] = 'project';
        $data['entity_id'] = $project->id;

        ManualAdjustment::query()->create($data);

        return back()->with('status', 'Корректировка сохранена.');
    }
}
