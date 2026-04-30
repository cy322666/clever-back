<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 32)->nullable();
            $table->timestamps();

        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('role_title')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('capacity_hours_per_week', 8, 2)->default(40);
            $table->decimal('weekly_limit_hours', 8, 2)->default(40);
            $table->decimal('hourly_cost', 12, 2)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('category')->nullable();
            $table->string('source_type')->nullable();
            $table->string('status')->default('active');
            $table->string('risk_level')->default('normal');
            $table->string('support_classification')->nullable();
            $table->decimal('annual_revenue_estimate', 14, 2)->nullable();
            $table->decimal('margin_target', 6, 4)->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color', 32)->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
    }
};
