<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'adjustment_date' => 'date',
            'amount_decimal' => 'decimal:2',
            'hours_decimal' => 'decimal:2',
            'metadata' => 'array',
        ];
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
