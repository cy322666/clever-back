<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitabilitySnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'revenue_amount' => 'decimal:2',
            'expense_amount' => 'decimal:2',
            'gross_margin_amount' => 'decimal:2',
            'gross_margin_pct' => 'decimal:4',
            'hours_spent' => 'decimal:2',
            'hours_budget' => 'decimal:2',
            'source_payload' => 'array',
        ];
    }}
