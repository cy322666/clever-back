<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTimeEntry extends Model
{
    protected $fillable = [
        'task_id',
        'employee_id',
        'entry_date',
        'minutes',
        'external_id',
        'cost',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'minutes' => 'integer',
            'cost' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'weeek_uuid');
    }
}
