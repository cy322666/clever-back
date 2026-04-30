<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bank_statement_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_statement_rows', 'source_key')) {
                $table->string('source_key')->nullable()->after('data_import_batch_id');
            }

            if (! Schema::hasColumn('bank_statement_rows', 'external_id')) {
                $table->string('external_id')->nullable()->after('source_key');
            }
        });

        Schema::table('bank_statement_rows', function (Blueprint $table) {
            $table->index(['source_key', 'external_id'], 'bank_statement_rows_source_external_index');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_rows', function (Blueprint $table) {
            $table->dropIndex('bank_statement_rows_source_external_index');

            if (Schema::hasColumn('bank_statement_rows', 'external_id')) {
                $table->dropColumn('external_id');
            }

            if (Schema::hasColumn('bank_statement_rows', 'source_key')) {
                $table->dropColumn('source_key');
            }
        });
    }
};
