<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('manual_adjustments') || ! Schema::hasColumn('manual_adjustments', 'account_id')) {
            return;
        }

        DB::statement('alter table manual_adjustments drop column if exists account_id cascade');
    }

    public function down(): void
    {
        if (! Schema::hasTable('manual_adjustments') || Schema::hasColumn('manual_adjustments', 'account_id')) {
            return;
        }

        DB::statement('alter table manual_adjustments add column account_id bigint null');
    }
};
