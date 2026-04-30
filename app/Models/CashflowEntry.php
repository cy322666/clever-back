<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashflowEntry extends Model
{
    protected $fillable = [
        'source_type',
        'source_table',
        'source_record_id',
        'entry_date',
        'kind',
        'amount',
        'balance_after',
        'category',
        'description',
        'client_id',
        'project_id',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'payload' => 'array',
        ];
    }
}
