<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE entity_products ALTER COLUMN quantity TYPE double precision USING quantity::double precision');
        DB::statement('ALTER TABLE entity_products ALTER COLUMN unit_price TYPE double precision USING unit_price::double precision');
        DB::statement('ALTER TABLE entity_products ALTER COLUMN total_amount TYPE double precision USING total_amount::double precision');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE entity_products ALTER COLUMN quantity TYPE numeric(14,4) USING quantity::numeric(14,4)');
        DB::statement('ALTER TABLE entity_products ALTER COLUMN unit_price TYPE numeric(14,2) USING unit_price::numeric(14,2)');
        DB::statement('ALTER TABLE entity_products ALTER COLUMN total_amount TYPE numeric(14,2) USING total_amount::numeric(14,2)');
    }
};
