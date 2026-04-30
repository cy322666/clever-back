<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('data_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('bank_statement_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('occurred_at')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('direction')->default('in');
            $table->string('counterparty_name')->nullable();
            $table->string('purpose')->nullable();
            $table->string('category')->nullable();
            $table->foreignId('matched_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matched_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->string('status')->default('raw');
            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('revenue_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_statement_row_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system')->default('manual');
            $table->string('source_reference')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('RUB');
            $table->string('category')->nullable();
            $table->string('channel')->nullable();
            $table->string('status')->default('posted');
            $table->boolean('is_recurring')->default(false);
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_statement_row_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_system')->default('manual');
            $table->string('source_reference')->nullable();
            $table->dateTime('transaction_date')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('RUB');
            $table->string('category')->nullable();
            $table->string('vendor_name')->nullable();
            $table->string('status')->default('posted');
            $table->boolean('is_fixed')->default(false);
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('cashflow_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->string('source_table');
            $table->unsignedBigInteger('source_record_id');
            $table->date('entry_date');
            $table->string('kind');
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('balance_after', 14, 2)->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

        });

        Schema::create('profitability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('snapshot_date');
            $table->decimal('revenue_amount', 14, 2)->default(0);
            $table->decimal('expense_amount', 14, 2)->default(0);
            $table->decimal('gross_margin_amount', 14, 2)->default(0);
            $table->decimal('gross_margin_pct', 6, 4)->default(0);
            $table->decimal('hours_spent', 12, 2)->default(0);
            $table->decimal('hours_budget', 12, 2)->nullable();
            $table->jsonb('source_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('manual_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('adjustment_type');
            $table->date('adjustment_date');
            $table->decimal('amount_decimal', 14, 2)->nullable();
            $table->decimal('hours_decimal', 12, 2)->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_adjustments');
        Schema::dropIfExists('profitability_snapshots');
        Schema::dropIfExists('cashflow_entries');
        Schema::dropIfExists('expense_transactions');
        Schema::dropIfExists('revenue_transactions');
        Schema::dropIfExists('bank_statement_rows');
        Schema::dropIfExists('data_import_batches');
    }
};
