<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $fillable = [
        'client_id',
        'manager_employee_id',
        'responsible_employee_id',
        'support_contract_id',
        'source_system',
        'external_id',
        'name',
        'code',
        'status',
        'project_type',
        'current_stage',
        'start_date',
        'due_date',
        'next_action_at',
        'last_activity_at',
        'planned_hours',
        'spent_hours',
        'budget_amount',
        'revenue_amount',
        'margin_pct',
        'risk_score',
        'health_status',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'project_type' => 'string',
            'start_date' => 'date',
            'due_date' => 'date',
            'next_action_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'planned_hours' => 'decimal:2',
            'spent_hours' => 'decimal:2',
            'budget_amount' => 'decimal:2',
            'revenue_amount' => 'decimal:2',
            'margin_pct' => 'decimal:4',
            'risk_score' => 'decimal:4',
        ];
    }
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function supportContract(): BelongsTo
    {
        return $this->belongsTo(SupportContract::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
