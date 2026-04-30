<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('alerts') || ! Schema::hasColumn('alerts', 'account_id')) {
            return;
        }

        DB::statement('alter table alerts drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('alerts') || Schema::hasColumn('alerts', 'account_id')) {
            return;
        }

        DB::statement('alter table alerts add column account_id bigint null');
    }
};
