<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueTransaction extends Model
{
    protected $fillable = [
        'client_id',
        'project_id',
        'bank_statement_row_id',
        'source_system',
        'source_reference',
        'transaction_date',
        'posted_at',
        'amount',
        'currency',
        'category',
        'channel',
        'status',
        'is_recurring',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'posted_at' => 'datetime',
            'amount' => 'decimal:2',
            'is_recurring' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
