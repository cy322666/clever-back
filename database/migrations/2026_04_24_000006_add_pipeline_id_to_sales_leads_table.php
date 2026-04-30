<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->foreignId('pipeline_id')->nullable()->after('client_id')->constrained('pipelines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pipeline_id');
        });
    }
};
