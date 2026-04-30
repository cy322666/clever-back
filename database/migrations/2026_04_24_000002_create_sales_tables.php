<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('type')->default('sales');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('order_index')->default(0);
            $table->decimal('probability', 5, 2)->default(0);
            $table->boolean('is_success')->default(false);
            $table->boolean('is_failure')->default(false);
            $table->timestamps();
        });

        Schema::create('sales_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->string('source_channel')->nullable();
            $table->string('status')->default('new');
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->timestamp('lead_created_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pipeline_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source_system')->default('manual');
            $table->string('external_id')->nullable();
            $table->string('name');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('probability', 5, 2)->default(0);
            $table->string('status')->default('open');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('planned_close_at')->nullable();
            $table->string('closed_reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_pipeline_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('leads_count')->default(0);
            $table->unsignedInteger('opportunities_count')->default(0);
            $table->decimal('amount_sum', 14, 2)->default(0);
            $table->decimal('weighted_amount', 14, 2)->default(0);
            $table->decimal('conversion_rate', 6, 4)->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_pipeline_snapshots');
        Schema::dropIfExists('sales_opportunities');
        Schema::dropIfExists('sales_leads');
        Schema::dropIfExists('stages');
        Schema::dropIfExists('pipelines');
    }
};
