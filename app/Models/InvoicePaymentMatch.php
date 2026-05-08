<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePaymentMatch extends Model
{
    protected $fillable = [
        'bank_statement_row_id',
        'revenue_transaction_id',
        'invoice_id',
        'client_id',
        'source_key',
        'status',
        'inn',
        'counterparty_name',
        'payment_amount',
        'invoice_amount',
        'reason',
        'applied_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'payment_amount' => 'decimal:2',
            'invoice_amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function bankStatementRow(): BelongsTo
    {
        return $this->belongsTo(BankStatementRow::class);
    }

    public function revenueTransaction(): BelongsTo
    {
        return $this->belongsTo(RevenueTransaction::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
