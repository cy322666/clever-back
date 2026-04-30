<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementRow extends Model
{
    protected $fillable = [
        'data_import_batch_id',
        'occurred_at',
        'amount',
        'direction',
        'counterparty_name',
        'purpose',
        'category',
        'matched_client_id',
        'matched_project_id',
        'status',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'amount' => 'decimal:2',
            'raw_payload' => 'array',
        ];
    }
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DataImportBatch::class, 'data_import_batch_id');
    }

    public function matchedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'matched_client_id');
    }
}
