<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasTable('source_mappings')) {
            return;
        }

        if (! Schema::hasColumn('employees', 'weeek_uuid')) {
            return;
        }

        DB::statement(<<<'SQL'
            update employees
            set weeek_uuid = source_mappings.external_id::uuid
            from source_mappings
            where source_mappings.source_key = 'weeek'
                and source_mappings.external_type = 'user'
                and source_mappings.internal_type = 'App\Models\Employee'
                and source_mappings.internal_id = employees.id
                and employees.weeek_uuid is null
                and source_mappings.external_id ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        SQL);

        DB::statement(<<<'SQL'
            update employees
            set name = source_mappings.label
            from source_mappings
            where source_mappings.source_key = 'weeek'
                and source_mappings.external_type = 'user'
                and source_mappings.internal_type = 'App\Models\Employee'
                and source_mappings.internal_id = employees.id
                and source_mappings.label is not null
                and trim(source_mappings.label) <> ''
                and (
                    employees.name is null
                    or trim(employees.name) = ''
                    or lower(employees.name) like 'weeek user%'
                )
        SQL);
    }

    public function down(): void
    {
        //
    }
};
