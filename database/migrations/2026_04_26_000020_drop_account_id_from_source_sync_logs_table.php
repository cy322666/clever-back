<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('source_sync_logs') || ! Schema::hasColumn('source_sync_logs', 'account_id')) {
            return;
        }

        DB::statement('alter table source_sync_logs drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('source_sync_logs') || Schema::hasColumn('source_sync_logs', 'account_id')) {
            return;
        }

        DB::statement('alter table source_sync_logs add column account_id bigint null');
    }
};
