<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('source_mappings') || ! Schema::hasColumn('source_mappings', 'account_id')) {
            return;
        }

        DB::statement('alter table source_mappings drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('source_mappings') || Schema::hasColumn('source_mappings', 'account_id')) {
            return;
        }

        DB::statement('alter table source_mappings add column account_id bigint null');
    }
};
