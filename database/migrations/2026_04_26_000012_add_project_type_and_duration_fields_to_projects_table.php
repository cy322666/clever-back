<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('project_type')->default('hourly_until_date')->after('source_system');
        });

        DB::statement("
            update projects
            set project_type = case
                when support_contract_id is not null then 'support_monthly'
                else 'hourly_until_date'
            end
        ");
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('project_type');
        });
    }
};
