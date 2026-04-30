<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceConnection extends Model
{
    protected $fillable = [
        'source_key',
        'name',
        'driver',
        'status',
        'is_enabled',
        'last_synced_at',
        'last_error_at',
        'last_error_message',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_error_at' => 'datetime',
            'settings' => 'array',
        ];
    }
    public function syncLogs(): HasMany
    {
        return $this->hasMany(SourceSyncLog::class);
    }
}
