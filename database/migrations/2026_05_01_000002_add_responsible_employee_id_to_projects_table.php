<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('projects') || Schema::hasColumn('projects', 'responsible_employee_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table
                ->foreignId('responsible_employee_id')
                ->nullable()
                ->after('manager_employee_id')
                ->constrained('employees')
                ->nullOnDelete();
        });

        DB::table('projects')
            ->whereNull('responsible_employee_id')
            ->whereNotNull('manager_employee_id')
            ->update(['responsible_employee_id' => DB::raw('manager_employee_id')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('projects') || ! Schema::hasColumn('projects', 'responsible_employee_id')) {
            return;
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_employee_id');
        });
    }
};
