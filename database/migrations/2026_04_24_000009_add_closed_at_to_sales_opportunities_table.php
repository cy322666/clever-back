<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('planned_close_at');
        });

        DB::table('sales_opportunities')
            ->whereNull('closed_at')
            ->update([
                'closed_at' => DB::raw('coalesce(won_at, lost_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });
    }
};
