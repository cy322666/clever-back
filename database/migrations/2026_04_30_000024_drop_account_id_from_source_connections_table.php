<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('source_connections')) {
            return;
        }

        DB::statement('alter table source_connections drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('source_connections') || Schema::hasColumn('source_connections', 'account_id')) {
            return;
        }

        DB::statement('alter table source_connections add column account_id bigint null');
    }
};
