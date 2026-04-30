<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key');
            $table->string('source_type')->default('amoCRM');
            $table->string('external_id');
            $table->unsignedBigInteger('catalog_id')->nullable();
            $table->string('name');
            $table->string('customer_external_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('category')->nullable();
            $table->string('payment_status')->nullable();
            $table->unsignedBigInteger('payment_status_enum_id')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('vat_type')->nullable();
            $table->string('payment_hash')->nullable();
            $table->string('invoice_link')->nullable();
            $table->timestamp('invoice_date')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_connection_id', 'external_id']);
            $table->index(['source_key', 'invoice_date']);
            $table->index(['payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
