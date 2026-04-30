<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportContract extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'monthly_hours_limit' => 'decimal:2',
            'monthly_fee' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'margin_pct' => 'decimal:4',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    public function usagePeriods(): HasMany
    {
        return $this->hasMany(SupportUsagePeriod::class);
    }
}
