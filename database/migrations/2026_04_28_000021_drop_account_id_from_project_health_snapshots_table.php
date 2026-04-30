<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('project_health_snapshots') || ! Schema::hasColumn('project_health_snapshots', 'account_id')) {
            return;
        }

        DB::statement('alter table project_health_snapshots drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('project_health_snapshots') || Schema::hasColumn('project_health_snapshots', 'account_id')) {
            return;
        }

        DB::statement('alter table project_health_snapshots add column account_id bigint null');
    }
};
