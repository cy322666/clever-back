<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOpportunity extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'amount' => 'decimal:2',
            'probability' => 'decimal:2',
            'opened_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'planned_close_at' => 'datetime',
        ];
    }
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }
}
