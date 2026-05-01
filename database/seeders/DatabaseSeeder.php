<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\CashflowEntry;
use App\Models\Client;
use App\Models\DataImportBatch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExpenseTransaction;
use App\Models\ManualAdjustment;
use App\Models\Pipeline;
use App\Models\Project;
use App\Models\ProjectHealthSnapshot;
use App\Models\ProjectStage;
use App\Models\RevenueTransaction;
use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Models\SalesPipelineSnapshot;
use App\Models\SourceConnection;
use App\Models\SourceMapping;
use App\Models\SupportContract;
use App\Models\SupportUsagePeriod;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskTimeEntry;
use App\Models\Stage;
use App\Models\User;
use App\Models\ProfitabilitySnapshot;
use App\Services\Alerts\AlertDetectorService;
use App\Services\Analytics\MetricSnapshotService;
use App\Support\AccountContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $faker = new class {
            protected array $words = [
                'alpha', 'beta', 'delta', 'sales', 'growth', 'cloud', 'digital', 'smart', 'owner',
                'client', 'project', 'delivery', 'support', 'finance', 'risk', 'signal', 'market',
            ];

            public function randomElement(array $values): mixed
            {
                return $values[array_rand($values)];
            }

            public function randomFloat(int $decimals = 2, float $min = 0, float $max = 1): float
            {
                return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), $decimals);
            }

            public function boolean(int $chance = 50): bool
            {
                return mt_rand(1, 100) <= $chance;
            }

            public function sentence(int $words = 6): string
            {
                $pool = $this->words;
                shuffle($pool);

                return ucfirst(implode(' ', array_slice($pool, 0, $words))).'.';
            }

            public function company(): string
            {
                $prefixes = ['Nova', 'Prime', 'Atlas', 'Vertex', 'Astra', 'Clever', 'Focus', 'Orbit'];
            $suffixes = ['Групп', 'Студия', 'Лаборатория', 'Системы', 'Партнеры', 'Проекты', 'Медиа', 'Диджитал'];

                return $prefixes[array_rand($prefixes)].' '.$suffixes[array_rand($suffixes)];
            }
        };
        $now = CarbonImmutable::now();
        $account = app(\App\Services\CompanyResolver::class)->resolve();
        $company = $account;

        if (User::query()->where('email', 'owner@example.com')->exists()) {
            $this->seedSourceConnections($account, $now, $faker, true);

            return;
        }

        User::query()->create([
            'name' => 'Собственник',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $departments = collect(['Продажи', 'Производство', 'Сопровождение', 'Финансы', 'Управление'])->map(function (string $name) use ($company) {
            return Department::query()->create([
                'name' => $name,
            ]);
        });

        $employees = collect([
            ['name' => 'Анна Морозова', 'department' => 'Продажи', 'role' => 'Руководитель продаж', 'capacity' => 40],
            ['name' => 'Дмитрий Лебедев', 'department' => 'Производство', 'role' => 'Руководитель проекта', 'capacity' => 38],
            ['name' => 'Елена Соколова', 'department' => 'Производство', 'role' => 'Аналитик', 'capacity' => 36],
            ['name' => 'Игорь Петров', 'department' => 'Сопровождение', 'role' => 'Руководитель сопровождения', 'capacity' => 34],
            ['name' => 'Марина Кузнецова', 'department' => 'Финансы', 'role' => 'Финансовый менеджер', 'capacity' => 30],
            ['name' => 'Павел Орлов', 'department' => 'Производство', 'role' => 'Разработчик', 'capacity' => 40],
        ])->map(function (array $data) use ($company, $departments) {
            $salary = rand(150000, 280000);
            $monthlyNormHours = max(1, $data['capacity'] * 4.333333);

            return Employee::query()->create([
                'department_id' => $departments->firstWhere('name', $data['department'])->id,
                'name' => $data['name'],
                'role_title' => $data['role'],
                'email' => str()->slug($data['name']).'@example.com',
                'capacity_hours_per_week' => $data['capacity'],
                'weekly_limit_hours' => $data['capacity'],
                'salary_amount' => $salary,
                'hourly_cost' => round($salary / $monthlyNormHours, 2),
                'metadata' => ['demo' => true],
            ]);
        });

        $clients = collect([
            ['name' => 'Ритейл Плюс', 'risk' => 'normal', 'category' => 'Ритейл'],
            ['name' => 'АвтоДилер Центр', 'risk' => 'high', 'category' => 'Авто'],
            ['name' => 'МедТех', 'risk' => 'normal', 'category' => 'Медицина'],
            ['name' => 'Alpha Logistics', 'risk' => 'critical', 'category' => 'Логистика'],
            ['name' => 'СтройТочка', 'risk' => 'normal', 'category' => 'Строительство'],
            ['name' => 'Sky Finance', 'risk' => 'normal', 'category' => 'Финансы'],
            ['name' => 'B2B Market', 'risk' => 'high', 'category' => 'Электронная коммерция'],
            ['name' => 'LeadForge', 'risk' => 'normal', 'category' => 'SaaS-сервис'],
        ])->map(function (array $data) use ($company, $employees, $faker) {
            return Client::query()->create([
                'owner_employee_id' => $employees->random()->id,
                'name' => $data['name'],
                'legal_name' => $data['name'].' ООО',
                'category' => $data['category'],
                'source_type' => 'amoCRM',
                'status' => 'active',
                'risk_level' => $data['risk'],
                'support_classification' => $faker->randomElement(['A', 'B', 'C']),
                'annual_revenue_estimate' => rand(3000000, 18000000),
                'margin_target' => $faker->randomFloat(4, 0.08, 0.32),
                'note' => $faker->sentence(),
            ]);
        });

        $pipelineNames = ['Основная', 'Повторные', 'Виджеты', 'Сопровождение'];
        $pipelines = collect($pipelineNames)->values()->map(function (string $name, int $index) use ($company) {
            return Pipeline::query()->create([
                'source_system' => 'amoCRM',
                'external_id' => (string) (100000 + $index + 1),
                'name' => $name,
                'type' => 'sales',
                'is_active' => true,
            ]);
        })->values();

        $stageTemplates = collect([
            ['name' => 'Новая', 'probability' => 5],
            ['name' => 'Квалификация', 'probability' => 15],
            ['name' => 'Предложение', 'probability' => 40],
            ['name' => 'Согласование', 'probability' => 65],
            ['name' => 'Успешно реализовано', 'probability' => 100, 'success' => true],
            ['name' => 'Закрыто и не реализовано', 'probability' => 0, 'failure' => true],
        ]);

        $stagesByPipeline = [];

        $pipelines->each(function (Pipeline $pipeline) use (&$stagesByPipeline, $company, $stageTemplates) {
            $stagesByPipeline[$pipeline->id] = $stageTemplates->map(function (array $stage, int $index) use ($company, $pipeline) {
                return Stage::query()->create([
                    'pipeline_id' => $pipeline->id,
                    'source_system' => 'amoCRM',
                    'external_id' => 'amo-'.$pipeline->id.'-'.$index,
                    'name' => $stage['name'],
                    'order_index' => $index + 1,
                    'probability' => $stage['probability'],
                    'is_success' => (bool) ($stage['success'] ?? false),
                    'is_failure' => (bool) ($stage['failure'] ?? false),
                ]);
            })->values();
        });

        $leads = collect(range(1, 24))->map(function (int $i) use ($company, $clients, $employees, $pipelines, $now, $faker) {
            $leadDate = $now->subDays(rand(0, 45))->subHours(rand(0, 18));
            $pipeline = $pipelines->random();

            return SalesLead::query()->create([
                'client_id' => $clients->random()->id,
                'pipeline_id' => $pipeline->id,
                'owner_employee_id' => $employees->random()->id,
                'source_system' => 'amoCRM',
                'external_id' => 'lead-'.$i,
                'name' => 'Заявка #'.$i,
                'source_channel' => $faker->randomElement(['Сайт', 'Сарафан', 'Реклама', 'Telegram', 'Холодный контакт']),
                'status' => $faker->randomElement(['new', 'qualified', 'contacted', 'converted']),
                'budget_amount' => rand(120000, 850000),
                'lead_created_at' => $leadDate,
                'last_activity_at' => $leadDate->addDays(rand(0, 6)),
                'metadata' => ['demo' => true],
            ]);
        });

        $deals = collect(range(1, 26))->map(function (int $i) use ($company, $clients, $employees, $stagesByPipeline, $now, $pipelines, $faker) {
            $opened = $now->subDays(rand(2, 60))->subHours(rand(0, 20));
            $status = $faker->randomElement(['open', 'won', 'lost']);
            $pipeline = $pipelines->random();
            $stages = $stagesByPipeline[$pipeline->id];
            $stage = match ($status) {
                'won' => $stages->firstWhere('is_success', true) ?? $stages->last(),
                'lost' => $stages->firstWhere('is_failure', true) ?? $stages->last(),
                default => $stages->take(4)->random(),
            };

            return SalesOpportunity::query()->create([
                'client_id' => $clients->random()->id,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'owner_employee_id' => $employees->random()->id,
                'source_system' => 'amoCRM',
                'external_id' => 'deal-'.$i,
                'name' => 'Deal #'.$i,
                'amount' => rand(180000, 2400000),
                'probability' => $status === 'won' ? 100 : ($status === 'lost' ? 0 : rand(20, 85)),
                'status' => $status,
                'opened_at' => $opened,
                'won_at' => $status === 'won' ? $opened->addDays(rand(3, 30)) : null,
                'lost_at' => $status === 'lost' ? $opened->addDays(rand(3, 20)) : null,
                'last_activity_at' => $opened->addDays(rand(0, 12)),
                'planned_close_at' => $opened->addDays(rand(8, 35)),
                'closed_reason' => $status === 'lost' ? $faker->randomElement(['Цена', 'Не вышли на связь', 'Отложили']) : null,
                'metadata' => ['demo' => true],
            ]);
        });

        $pipelines->each(function (Pipeline $pipeline) use ($company, $stagesByPipeline, $now, $faker) {
            foreach (range(1, 18) as $index) {
                $stages = $stagesByPipeline[$pipeline->id];

                SalesPipelineSnapshot::query()->create([
                    'pipeline_id' => $pipeline->id,
                    'stage_id' => $stages->random()->id,
                    'snapshot_date' => $now->subDays(18 - $index)->toDateString(),
                    'leads_count' => rand(4, 11),
                    'opportunities_count' => rand(2, 8),
                    'amount_sum' => rand(500000, 3200000),
                    'weighted_amount' => rand(300000, 1800000),
                    'conversion_rate' => $faker->randomFloat(4, 0.08, 0.45),
                ]);
            }
        });

        $projects = collect(range(1, 12))->map(function (int $i) use ($company, $clients, $employees, $now, $faker) {
            $start = $now->subDays(rand(10, 100));
            $spent = rand(40, 420);
            $planned = rand(50, 350);
            $revenue = rand(350000, 2900000);
            $expense = rand(180000, 2100000);
            $projectType = $faker->randomElement(['support_monthly', 'hourly_until_date', 'hourly_package', 'hourly_until_date', 'support_monthly']);
            $dueDate = $projectType === 'hourly_until_date'
                ? $start->copy()->addDays(rand(25, 75))->toDateString()
                : null;
            $manager = $employees->random();

            return Project::query()->create([
                'client_id' => $clients->random()->id,
                'manager_employee_id' => $manager->id,
                'responsible_employee_id' => $manager->id,
                'source_system' => 'Weeek',
                'external_id' => 'project-'.$i,
                'name' => 'Проект #'.$i,
                'code' => 'PRJ-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'status' => $faker->randomElement(['active', 'active', 'active', 'paused', 'done']),
                'project_type' => $projectType,
                'current_stage' => $faker->randomElement(['Исследование', 'Разработка', 'Тестирование', 'Запуск', 'Сопровождение']),
                'start_date' => $start->toDateString(),
                'due_date' => $dueDate,
                'next_action_at' => $faker->boolean(80) ? $now->addDays(rand(1, 8)) : null,
                'last_activity_at' => $faker->boolean(75) ? $now->subDays(rand(0, 10)) : $now->subDays(rand(12, 40)),
                'planned_hours' => $planned,
                'spent_hours' => $spent,
                'budget_amount' => $revenue,
                'revenue_amount' => $revenue,
                'margin_pct' => ($revenue - $expense) / $revenue,
                'risk_score' => $faker->randomFloat(4, 0.05, 0.95),
                'health_status' => $faker->randomElement(['green', 'green', 'yellow', 'red']),
                'note' => $faker->sentence(),
                'metadata' => ['demo' => true],
            ]);
        });

        foreach ($projects as $project) {
            collect(range(1, 3))->each(function (int $index) use ($project, $now, $faker) {
                ProjectStage::query()->create([
                    'project_id' => $project->id,
                    'name' => $faker->randomElement(['Исследование', 'Дизайн', 'Разработка', 'Тестирование', 'Запуск']),
                    'order_index' => $index,
                    'status' => $index === 3 ? 'active' : 'done',
                    'started_at' => $now->subDays(rand(5, 45)),
                    'ended_at' => $index === 3 ? null : $now->subDays(rand(1, 20)),
                    'is_active' => $index === 3,
                ]);
            });

            ProjectHealthSnapshot::query()->create([
                'project_id' => $project->id,
                'snapshot_date' => $now->toDateString(),
                'health_status' => $project->health_status,
                'risk_score' => $project->risk_score,
                'planned_hours' => $project->planned_hours,
                'spent_hours' => $project->spent_hours,
                'budget_hours' => $project->planned_hours,
                'revenue_amount' => $project->revenue_amount,
                'payload' => ['demo' => true],
            ]);
        }

        $contracts = collect(range(1, 8))->map(function (int $i) use ($company, $clients, $employees, $now, $faker) {
            return SupportContract::query()->create([
                'client_id' => $clients->random()->id,
                'owner_employee_id' => $employees->random()->id,
                'name' => 'Сопровождение '.$i,
                'contract_type' => 'support',
                'monthly_hours_limit' => rand(12, 60),
                'monthly_fee' => rand(45000, 260000),
                'start_date' => $now->subMonths(rand(1, 16))->toDateString(),
                'end_date' => null,
                'status' => 'active',
                'margin_pct' => $faker->randomFloat(4, 0.08, 0.6),
            ]);
        });

        foreach ($contracts as $contract) {
            SupportUsagePeriod::query()->create([
                'support_contract_id' => $contract->id,
                'period_start' => $now->startOfMonth()->toDateString(),
                'period_end' => $now->endOfMonth()->toDateString(),
                'planned_hours' => $contract->monthly_hours_limit,
                'actual_hours' => rand(6, 80),
                'overage_hours' => rand(0, 20),
                'note' => $faker->sentence(),
            ]);
        }

        $tasks = collect(range(1, 36))->map(function (int $i) use ($company, $projects, $clients, $employees, $now, $faker) {
            $project = $projects->random();
            $assignee = $employees->random();
            $due = $now->subDays(rand(-6, 12));

            return Task::query()->create([
                'project_id' => $project->id,
                'client_id' => $project->client_id,
                'assignee_employee_id' => $assignee->id,
                'source_system' => 'Weeek',
                'external_id' => 'task-'.$i,
                'title' => 'Task #'.$i,
                'type' => $faker->randomElement(['task', 'bug', 'request']),
                'status' => $faker->randomElement(['open', 'in_progress', 'done']),
                'priority' => $faker->randomElement(['low', 'normal', 'high']),
                'due_at' => $due,
                'started_at' => $faker->boolean(70) ? $due->subDays(rand(1, 3)) : null,
                'completed_at' => $faker->boolean(35) ? $due->addDays(rand(0, 4)) : null,
                'estimate_hours' => rand(1, 18),
                'spent_hours' => rand(0, 20),
                'is_blocked' => $faker->boolean(18),
                'last_activity_at' => $due->subDays(rand(0, 4)),
            ]);
        });

        foreach ($tasks as $task) {
            collect(range(1, rand(1, 4)))->each(function () use ($task, $employees, $now, $company, $faker) {
                TaskTimeEntry::query()->create([
                    'task_id' => $task->id,
                    'employee_id' => $employees->random()->id,
                    'entry_date' => $now->subDays(rand(0, 28))->toDateString(),
                    'minutes' => rand(30, 360),
                    'billable_minutes' => rand(0, 300),
                    'source_system' => 'manual',
                    'description' => $faker->sentence(),
                    'rate' => rand(1200, 2400),
                    'cost' => rand(900, 1900),
                ]);
            });
        }

        foreach (range(1, 22) as $i) {
            $date = $now->subDays(rand(0, 35));
            $amount = rand(15000, 240000);
            $revenue = RevenueTransaction::query()->create([
                'client_id' => $clients->random()->id,
                'project_id' => $projects->random()->id,
                'source_system' => 'bank-import',
                'source_reference' => 'seed-revenue-'.$i,
                'transaction_date' => $date,
                'posted_at' => $date,
                'amount' => $amount,
                'currency' => 'RUB',
                'category' => $faker->randomElement(['Оплата проекта', 'Сопровождение', 'Консалтинг']),
                'channel' => $faker->randomElement(['bank', 'card', 'cash']),
                'status' => 'posted',
                'is_recurring' => $faker->boolean(20),
                'note' => $faker->sentence(),
            ]);

            CashflowEntry::query()->create([
                'source_type' => RevenueTransaction::class,
                'source_table' => 'revenue_transactions',
                'source_record_id' => $revenue->id,
                'entry_date' => $date->toDateString(),
                'kind' => 'in',
                'amount' => $amount,
                'category' => 'Выручка',
                'description' => 'Демо-поступление '.$i,
                'client_id' => $clients->random()->id,
                'project_id' => $projects->random()->id,
            ]);
        }

        foreach (range(1, 18) as $i) {
            $date = $now->subDays(rand(0, 35));
            $amount = rand(12000, 190000);
            $expense = ExpenseTransaction::query()->create([
                'client_id' => $faker->boolean(15) ? $clients->random()->id : null,
                'project_id' => $faker->boolean(25) ? $projects->random()->id : null,
                'source_system' => 'bank-import',
                'source_reference' => 'seed-expense-'.$i,
                'transaction_date' => $date,
                'posted_at' => $date,
                'amount' => $amount,
                'currency' => 'RUB',
                'category' => $faker->randomElement(['Зарплаты', 'Маркетинг', 'ПО', 'Подрядчики', 'Офис']),
                'vendor_name' => $faker->company(),
                'status' => 'posted',
                'is_fixed' => $faker->boolean(50),
                'note' => $faker->sentence(),
            ]);

            CashflowEntry::query()->create([
                'source_type' => ExpenseTransaction::class,
                'source_table' => 'expense_transactions',
                'source_record_id' => $expense->id,
                'entry_date' => $date->toDateString(),
                'kind' => 'out',
                'amount' => $amount,
                'category' => 'Расход',
                'description' => 'Демо-расход '.$i,
                'client_id' => null,
                'project_id' => null,
            ]);
        }

        foreach (range(1, 6) as $i) {
            DataImportBatch::query()->create([
                'user_id' => 1,
                'source_type' => 'bank',
                'file_name' => 'seed-'.$i.'.csv',
                'file_path' => 'imports/seed-'.$i.'.csv',
                'status' => 'completed',
                'row_count' => rand(12, 44),
                'processed_count' => rand(12, 44),
                'imported_at' => $now->subDays(rand(1, 35)),
            ]);
        }

        $this->seedSourceConnections($account, $now, $faker, true);

        foreach (range(1, 10) as $i) {
            ProfitabilitySnapshot::query()->create([
                'client_id' => $clients->random()->id,
                'project_id' => $projects->random()->id,
                'snapshot_date' => $now->subDays($i)->toDateString(),
                'revenue_amount' => rand(120000, 1450000),
                'expense_amount' => rand(60000, 1100000),
                'gross_margin_amount' => rand(20000, 400000),
                'gross_margin_pct' => $faker->randomFloat(4, 0.04, 0.52),
                'hours_spent' => rand(15, 220),
                'hours_budget' => rand(20, 260),
                'source_payload' => ['demo' => true],
            ]);
        }

        foreach (range(1, 6) as $i) {
            Alert::query()->create([
                'source_key' => 'system',
                'type' => $faker->randomElement(['project_idle', 'deal_idle', 'support_overage', 'low_margin_client']),
                'severity' => $faker->randomElement(['warning', 'critical']),
                'status' => 'open',
                'title' => 'Демо-риск #'.$i,
                'description' => $faker->sentence(),
                'entity_type' => Project::class,
                'entity_id' => $projects->random()->id,
                'score' => $faker->randomFloat(4, 0.2, 0.98),
                'detected_at' => $now->subDays(rand(0, 3)),
            ]);
        }

        collect([
            ['name' => 'VIP', 'color' => '#38bdf8'],
            ['name' => 'Риск', 'color' => '#f43f5e'],
            ['name' => 'Сопровождение', 'color' => '#22c55e'],
        ])->each(function (array $tag) use ($company) {
            Tag::query()->create([
                'name' => $tag['name'],
                'color' => $tag['color'],
            ]);
        });

        collect([
            ['source_key' => 'amo', 'external_type' => 'lead', 'external_id' => 'lead-1', 'internal_type' => SalesLead::class, 'internal_id' => $leads->first()->id],
            ['source_key' => 'amo', 'external_type' => 'deal', 'external_id' => 'deal-1', 'internal_type' => SalesOpportunity::class, 'internal_id' => $deals->first()->id],
            ['source_key' => 'weeek', 'external_type' => 'project', 'external_id' => 'project-1', 'internal_type' => Project::class, 'internal_id' => $projects->first()->id],
        ])->each(function (array $mapping) use ($company) {
            SourceMapping::query()->create([
                'source_key' => $mapping['source_key'],
                'external_type' => $mapping['external_type'],
                'external_id' => $mapping['external_id'],
                'internal_type' => $mapping['internal_type'],
                'internal_id' => $mapping['internal_id'],
                'label' => 'Демо-связь',
                'is_primary' => true,
                'metadata' => ['demo' => true],
            ]);
        });

        foreach (range(1, 8) as $i) {
            ManualAdjustment::query()->create([
                'user_id' => 1,
                'entity_type' => $faker->randomElement(['Project', 'Client', 'SupportContract']),
                'entity_id' => rand(1, 12),
                'adjustment_type' => $faker->randomElement(['hours_limit', 'budget', 'client_classification', 'project_budget']),
                'adjustment_date' => $now->subDays(rand(0, 30)),
                'amount_decimal' => $faker->boolean(70) ? rand(10000, 180000) : null,
                'hours_decimal' => $faker->boolean(55) ? rand(1, 24) : null,
                'note' => $faker->sentence(),
                'metadata' => ['demo' => true],
            ]);
        }

    }

    protected function seedSourceConnections(AccountContext $account, CarbonImmutable $now, $faker, bool $pruneUnused = false): void
    {
        if ($pruneUnused) {
            SourceConnection::query()
                ->whereNotIn('source_key', ['amo', 'weeek', 'bank'])
                ->delete();
        }

        $sources = [
            [
                'source_key' => 'amo',
                'name' => 'amoCRM',
                'driver' => 'amo',
            ],
            [
                'source_key' => 'weeek',
                'name' => 'Weeek',
                'driver' => 'weeek',
            ],
            [
                'source_key' => 'bank',
                'name' => 'Точка',
                'driver' => 'bank-import',
            ],
        ];

        foreach ($sources as $source) {
            SourceConnection::query()->updateOrCreate(
                [
                    'source_key' => $source['source_key'],
                ],
                [
                    'name' => $source['name'],
                    'driver' => $source['driver'],
                    'status' => $faker->randomElement(['active', 'active', 'active', 'error']),
                    'is_enabled' => true,
                    'last_synced_at' => $now->subHours(rand(1, 70)),
                    'last_error_at' => $faker->boolean(30) ? $now->subDays(rand(1, 5)) : null,
                    'last_error_message' => $faker->boolean(30) ? 'Demo sync warning' : null,
                    'settings' => ['demo' => true],
                ]
            );
        }
    }
}
