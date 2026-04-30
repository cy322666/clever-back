<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'score' => 'decimal:4',
            'metadata' => 'array',
        ];
    }}
