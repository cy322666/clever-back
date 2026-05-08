<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_payment_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_statement_row_id')->constrained()->cascadeOnDelete();
            $table->foreignId('revenue_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('source_key')->default('tochka');
            $table->string('status')->default('pending');
            $table->string('inn')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->decimal('payment_amount', 14, 2)->default(0);
            $table->decimal('invoice_amount', 14, 2)->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique('bank_statement_row_id');
            $table->index(['source_key', 'status']);
            $table->index(['inn', 'payment_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_matches');
    }
};
