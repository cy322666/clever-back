<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('data_import_batches') || ! Schema::hasColumn('data_import_batches', 'account_id')) {
            return;
        }

        DB::statement('alter table data_import_batches drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('data_import_batches') || Schema::hasColumn('data_import_batches', 'account_id')) {
            return;
        }

        DB::statement('alter table data_import_batches add column account_id bigint null');
    }
};
