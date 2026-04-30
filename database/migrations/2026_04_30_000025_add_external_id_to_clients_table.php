<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'source_type')) {
                $table->string('source_type')->nullable()->after('name');
            }

            if (! Schema::hasColumn('clients', 'external_id')) {
                $table->string('external_id')->nullable()->after('owner_employee_id');
            }
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->index(['source_type', 'external_id'], 'clients_source_external_index');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_source_external_index');

            if (Schema::hasColumn('clients', 'external_id')) {
                $table->dropColumn('external_id');
            }

            if (Schema::hasColumn('clients', 'source_type')) {
                $table->dropColumn('source_type');
            }
        });
    }
};
