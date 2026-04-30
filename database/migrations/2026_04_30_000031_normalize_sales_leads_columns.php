<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sales_leads')) {
            return;
        }

        Schema::table('sales_leads', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_leads', 'status_id')) {
                $table->string('status_id')->nullable()->after('source_channel');
            }

            if (! Schema::hasColumn('sales_leads', 'lead_closed_at')) {
                $table->timestamp('lead_closed_at')->nullable()->after('lead_created_at');
            }

            if (! Schema::hasColumn('sales_leads', 'pipeline_id')) {
                $table->foreignId('pipeline_id')->nullable()->after('client_id')->constrained('pipelines')->nullOnDelete();
            }
        });

        if (Schema::hasColumn('sales_leads', 'status')) {
            DB::statement("update sales_leads set status_id = status where status_id is null and status is not null");
        }

        DB::statement('create index if not exists sales_leads_status_id_index on sales_leads (status_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_leads')) {
            return;
        }

        DB::statement('drop index if exists sales_leads_status_id_index');
    }
};
