<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'value_numeric' => 'decimal:4',
            'payload' => 'array',
        ];
    }}
