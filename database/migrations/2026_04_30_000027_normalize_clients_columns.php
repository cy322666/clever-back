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
            if (! Schema::hasColumn('clients', 'owner_employee_id')) {
                $table->unsignedBigInteger('owner_employee_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('clients', 'external_id')) {
                $table->string('external_id')->nullable()->after('owner_employee_id');
            }

            if (! Schema::hasColumn('clients', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('clients', 'category')) {
                $table->string('category')->nullable()->after('legal_name');
            }

            if (! Schema::hasColumn('clients', 'source_type')) {
                $table->string('source_type')->nullable()->after('category');
            }

            if (! Schema::hasColumn('clients', 'status')) {
                $table->string('status')->default('active')->after('source_type');
            }

            if (! Schema::hasColumn('clients', 'metadata')) {
                $table->jsonb('metadata')->nullable();
            }
        });

        DB::statement('alter table clients drop column if exists company_id cascade');
        DB::statement('create index if not exists clients_source_external_index on clients (source_type, external_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('clients')) {
            return;
        }

        DB::statement('drop index if exists clients_source_external_index');
    }
};
