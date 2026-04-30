<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('revenue_transactions') || Schema::hasColumn('revenue_transactions', 'net_profit_percent')) {
            return;
        }

        Schema::table('revenue_transactions', function (Blueprint $table) {
            $table->decimal('net_profit_percent', 5, 2)->default(100)->after('amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('revenue_transactions') || ! Schema::hasColumn('revenue_transactions', 'net_profit_percent')) {
            return;
        }

        Schema::table('revenue_transactions', function (Blueprint $table) {
            $table->dropColumn('net_profit_percent');
        });
    }
};
