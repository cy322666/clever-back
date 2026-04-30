<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('source_type')->nullable();
            $table->string('external_id')->nullable();
            $table->string('status')->default('active');
            $table->string('subscription_status')->nullable();
            $table->string('periodicity')->nullable();
            $table->decimal('purchases_count', 12, 2)->default(0);
            $table->decimal('average_check', 14, 2)->default(0);
            $table->decimal('ltv', 14, 2)->default(0);
            $table->decimal('next_price', 14, 2)->nullable();
            $table->timestamp('next_date')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
