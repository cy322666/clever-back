<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    protected $fillable = [
        'project_id',
        'client_id',
        'assignee_employee_id',
        'source_system',
        'external_id',
        'title',
        'type',
        'status',
        'priority',
        'due_at',
        'started_at',
        'completed_at',
        'estimate_hours',
        'spent_hours',
        'is_blocked',
        'last_activity_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'estimate_hours' => 'decimal:2',
            'spent_hours' => 'decimal:2',
            'is_blocked' => 'boolean',
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TaskTimeEntry::class);
    }
}
