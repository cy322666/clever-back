<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPipelineSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'amount_sum' => 'decimal:2',
            'weighted_amount' => 'decimal:2',
            'conversion_rate' => 'decimal:4',
            'metadata' => 'array',
        ];
    }}
