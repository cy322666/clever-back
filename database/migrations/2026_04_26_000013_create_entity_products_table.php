<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('entity_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key');
            $table->string('source_type')->default('amoCRM');
            $table->string('external_id');
            $table->string('entity_type');
            $table->string('entity_external_id');
            $table->string('entity_name');
            $table->timestamp('entity_date')->nullable();
            $table->string('product_external_id')->nullable();
            $table->string('product_name');
            $table->string('product_sku')->nullable();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_products');
    }
};
