<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesLead extends Model
{
    protected $fillable = [
        'client_id',
        'owner_employee_id',
        'source_system',
        'external_id',
        'name',
        'source_channel',
        'status_id',
        'budget_amount',
        'lead_created_at',
        'lead_closed_at',
        'last_activity_at',
        'pipeline_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'budget_amount' => 'decimal:2',
            'lead_created_at' => 'datetime',
            'last_activity_at' => 'datetime',
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }
}
