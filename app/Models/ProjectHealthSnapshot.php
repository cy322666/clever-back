<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectHealthSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'risk_score' => 'decimal:4',
            'planned_hours' => 'decimal:2',
            'spent_hours' => 'decimal:2',
            'budget_hours' => 'decimal:2',
            'revenue_amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
