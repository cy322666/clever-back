<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('support_contract_id')->nullable();
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('status')->default('active');
            $table->string('current_stage')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('next_action_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->decimal('planned_hours', 12, 2)->nullable();
            $table->decimal('spent_hours', 12, 2)->default(0);
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->decimal('revenue_amount', 14, 2)->nullable();
            $table->decimal('margin_pct', 6, 4)->nullable();
            $table->decimal('risk_score', 6, 4)->default(0);
            $table->string('health_status')->default('green');
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });

        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('order_index')->default(0);
            $table->string('status')->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('project_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->string('health_status')->default('green');
            $table->decimal('risk_score', 6, 4)->default(0);
            $table->decimal('planned_hours', 12, 2)->nullable();
            $table->decimal('spent_hours', 12, 2)->default(0);
            $table->decimal('budget_hours', 12, 2)->nullable();
            $table->decimal('revenue_amount', 14, 2)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

        });

        Schema::create('support_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name');
            $table->string('contract_type')->default('support');
            $table->decimal('monthly_hours_limit', 12, 2)->default(0);
            $table->decimal('monthly_fee', 14, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->decimal('margin_pct', 6, 4)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('support_usage_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_contract_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('planned_hours', 12, 2)->default(0);
            $table->decimal('actual_hours', 12, 2)->default(0);
            $table->decimal('overage_hours', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->string('title');
            $table->string('type')->default('task');
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('estimate_hours', 12, 2)->nullable();
            $table->decimal('spent_hours', 12, 2)->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('task_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('entry_date');
            $table->unsignedInteger('minutes')->default(0);
            $table->unsignedInteger('billable_minutes')->default(0);
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->text('description')->nullable();
            $table->decimal('rate', 12, 2)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_time_entries');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('support_usage_periods');
        Schema::dropIfExists('support_contracts');
        Schema::dropIfExists('project_health_snapshots');
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('projects');
    }
};
