<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportUsagePeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'planned_hours' => 'decimal:2',
            'actual_hours' => 'decimal:2',
            'overage_hours' => 'decimal:2',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SupportContract::class, 'support_contract_id');
    }
}
