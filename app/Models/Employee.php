<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'department_id',
        'name',
        'weeek_uuid',
        'role_title',
        'email',
        'is_active',
        'capacity_hours_per_week',
        'weekly_limit_hours',
        'hourly_cost',
        'salary_amount',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'weeek_uuid' => 'string',
            'capacity_hours_per_week' => 'decimal:2',
            'weekly_limit_hours' => 'decimal:2',
            'hourly_cost' => 'decimal:2',
            'salary_amount' => 'decimal:2',
        ];
    }
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_employee_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TaskTimeEntry::class);
    }
}
