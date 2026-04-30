<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityProduct extends Model
{
    protected $fillable = [
        'source_connection_id',
        'source_key',
        'source_type',
        'external_id',
        'entity_type',
        'entity_external_id',
        'entity_name',
        'category',
        'entity_date',
        'product_external_id',
        'product_name',
        'product_sku',
        'quantity',
        'unit_price',
        'total_amount',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
//            'entity_date' => 'datetime',
            'quantity' => 'float',
            'unit_price' => 'float',
            'total_amount' => 'float',
        ];
    }
}
