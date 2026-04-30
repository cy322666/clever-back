<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('entity_products')) {
            return;
        }

        Schema::table('entity_products', function (Blueprint $table) {
            if (! Schema::hasColumn('entity_products', 'source_connection_id')) {
                $table->foreignId('source_connection_id')->nullable()->after('id');
            }

            if (! Schema::hasColumn('entity_products', 'source_key')) {
                $table->string('source_key')->nullable()->after('source_connection_id');
            }

            if (! Schema::hasColumn('entity_products', 'source_type')) {
                $table->string('source_type')->nullable()->after('source_key');
            }

            if (! Schema::hasColumn('entity_products', 'entity_date')) {
                $table->timestamp('entity_date')->nullable()->after('entity_name');
            }

            if (! Schema::hasColumn('entity_products', 'product_external_id')) {
                $table->string('product_external_id')->nullable()->after('entity_date');
            }

            if (! Schema::hasColumn('entity_products', 'product_name')) {
                $table->string('product_name')->nullable()->after('product_external_id');
            }

            if (! Schema::hasColumn('entity_products', 'product_sku')) {
                $table->string('product_sku')->nullable()->after('product_name');
            }
        });

        DB::statement("update entity_products set source_key = 'amo' where source_key is null or source_key = ''");
        DB::statement("update entity_products set source_type = 'amo' where source_type is null or source_type = ''");
        DB::statement("update entity_products set entity_name = concat(entity_type, ' #', entity_external_id) where entity_name is null or entity_name = ''");
        DB::statement("update entity_products set product_external_id = external_id where product_external_id is null or product_external_id = ''");
        DB::statement("update entity_products set product_name = concat('Товар #', external_id) where product_name is null or product_name = ''");
        DB::statement('update entity_products set quantity = 0 where quantity is null');
        DB::statement('update entity_products set unit_price = 0 where unit_price is null');
        DB::statement('update entity_products set total_amount = 0 where total_amount is null');

        DB::statement("alter table entity_products alter column source_key set default 'amo'");
        DB::statement("alter table entity_products alter column source_type set default 'amo'");
        DB::statement('alter table entity_products alter column source_key set not null');
        DB::statement('alter table entity_products alter column source_type set not null');
        DB::statement('alter table entity_products alter column entity_name set not null');
        DB::statement('alter table entity_products alter column product_name set not null');
        DB::statement('create index if not exists entity_products_source_entity_index on entity_products (source_key, entity_type, entity_external_id)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('entity_products')) {
            return;
        }

        DB::statement('drop index if exists entity_products_source_entity_index');
    }
};
