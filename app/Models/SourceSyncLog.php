<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceSyncLog extends Model
{
    protected $fillable = [
        'source_connection_id',
        'source_key',
        'source_type',
        'status',
        'started_at',
        'finished_at',
        'pulled_count',
        'created_count',
        'updated_count',
        'error_count',
        'error_message',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class, 'source_connection_id');
    }
}
