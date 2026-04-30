<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class DataImportBatch extends Model
{
    protected $fillable = [
        'user_id',
        'source_type',
        'file_name',
        'file_path',
        'status',
        'row_count',
        'processed_count',
        'imported_at',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'imported_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

}
