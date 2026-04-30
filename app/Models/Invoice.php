<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'source_connection_id',
        'source_key',
        'source_type',
        'external_id',
        'catalog_id',
        'name',
        'customer_external_id',
        'customer_name',
        'category',
        'payment_status',
        'payment_status_enum_id',
        'amount',
        'vat_type',
        'payment_hash',
        'invoice_link',
        'invoice_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'datetime',
            'metadata' => 'array',
            'amount' => 'float',
            'payment_status_enum_id' => 'integer',
            'catalog_id' => 'integer',
        ];
    }

    public function sourceConnection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class);
    }
}
