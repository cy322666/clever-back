<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseTransaction extends Model
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
        'vendor_name',
        'status',
        'is_fixed',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'posted_at' => 'datetime',
            'amount' => 'decimal:2',
            'is_fixed' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
