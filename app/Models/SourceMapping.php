<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceMapping extends Model
{
    protected $fillable = [
        'source_connection_id',
        'source_key',
        'external_type',
        'external_id',
        'internal_type',
        'internal_id',
        'label',
        'is_primary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'metadata' => 'array',
        ];
    }
}
