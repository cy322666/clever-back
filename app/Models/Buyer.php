<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Buyer extends Model
{
    protected $fillable = [
        'client_id',
        'owner_employee_id',
        'external_id',
        'name',
        'legal_name',
        'source_type',
        'status',
        'subscription_status',
        'periodicity',
        'purchases_count',
        'average_check',
        'ltv',
        'next_price',
        'next_date',
        'note',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'next_date' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }
}
