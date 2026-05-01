<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'inn')) {
                $table->string('inn')->nullable()->after('legal_name');
            }

            if (! Schema::hasColumn('clients', 'kpp')) {
                $table->string('kpp')->nullable()->after('inn');
            }
        });

        DB::statement('create index if not exists clients_inn_index on clients (inn)');
        DB::statement('create index if not exists clients_kpp_index on clients (kpp)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        DB::statement('drop index if exists clients_kpp_index');
        DB::statement('drop index if exists clients_inn_index');

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'kpp')) {
                $table->dropColumn('kpp');
            }

            if (Schema::hasColumn('clients', 'inn')) {
                $table->dropColumn('inn');
            }
        });
    }
};
