<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('task_time_entries') || ! Schema::hasColumn('task_time_entries', 'employee_id')) {
            return;
        }

        DB::statement('alter table task_time_entries drop constraint if exists task_time_entries_employee_id_foreign');

        $type = DB::table('information_schema.columns')
            ->where('table_name', 'task_time_entries')
            ->where('column_name', 'employee_id')
            ->value('udt_name');

        if ($type === 'uuid') {
            return;
        }

        DB::statement('alter table task_time_entries add column employee_uuid_tmp uuid null');
        DB::statement('
            update task_time_entries
            set employee_uuid_tmp = employees.weeek_uuid
            from employees
            where task_time_entries.employee_id = employees.id
                and employees.weeek_uuid is not null
        ');
        DB::statement('alter table task_time_entries drop column employee_id');
        DB::statement('alter table task_time_entries rename column employee_uuid_tmp to employee_id');
        DB::statement('create index if not exists task_time_entries_employee_id_index on task_time_entries (employee_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_time_entries') || ! Schema::hasColumn('task_time_entries', 'employee_id')) {
            return;
        }

        DB::statement('drop index if exists task_time_entries_employee_id_index');

        $type = DB::table('information_schema.columns')
            ->where('table_name', 'task_time_entries')
            ->where('column_name', 'employee_id')
            ->value('udt_name');

        if ($type !== 'uuid') {
            return;
        }

        DB::statement('alter table task_time_entries add column employee_id_tmp bigint null');
        DB::statement('
            update task_time_entries
            set employee_id_tmp = employees.id
            from employees
            where task_time_entries.employee_id = employees.weeek_uuid
        ');
        DB::statement('alter table task_time_entries drop column employee_id');
        DB::statement('alter table task_time_entries rename column employee_id_tmp to employee_id');
        DB::statement('alter table task_time_entries add constraint task_time_entries_employee_id_foreign foreign key (employee_id) references employees(id) on delete set null');
    }
};
