<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'bank_statement_rows',
            'revenue_transactions',
            'expense_transactions',
            'cashflow_entries',
        ] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'account_id')) {
                continue;
            }

            DB::statement("alter table {$table} drop column if exists account_id cascade");
        }
    }

    public function down(): void
    {
        foreach ([
            'bank_statement_rows',
            'revenue_transactions',
            'expense_transactions',
            'cashflow_entries',
        ] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'account_id')) {
                continue;
            }

            DB::statement("alter table {$table} add column account_id bigint null");
        }
    }
};
